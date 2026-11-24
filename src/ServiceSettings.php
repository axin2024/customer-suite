<?php

declare(strict_types=1);

namespace CustomerSuite;

final class ServiceSettings
{
    public const CATEGORIES = ['incident' => '故障', 'request' => '服务请求', 'billing' => '账务咨询', 'onboarding' => '实施支持'];
    public const PRIORITIES = ['P1', 'P2', 'P3', 'P4'];
    private const POLICY_COLUMNS = 'p.*,c.code AS calendar_code,c.revision AS calendar_revision,c.timezone,c.weekly_hours,c.exception_hours';

    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    public function calendar(Access $actor, int $company, array $input): array
    {
        $actor->allow($company, 'manager');
        $code = Input::key($input['code'], '日历代码', 48);
        $title = Input::text($input['name'], '日历名称', 120);
        $calendar = BusinessCalendar::validate($input['timezone'], $input['week'], $input['exceptions']);
        return $this->db->transaction(function () use ($actor, $company, $code, $title, $calendar): array {
            $this->lockCompany($company);
            $latest = $this->db->row('SELECT revision FROM service_calendars WHERE company_id = ? AND code = ? ORDER BY revision DESC LIMIT 1', [$company, $code]);
            $revision = ($latest['revision'] ?? 0) + 1;
            $id = $this->db->insert('INSERT INTO service_calendars (company_id,code,revision,name,timezone,weekly_hours,exception_hours,created_by)
                VALUES (?,?,?,?,?,?,?,?)', [$company, $code, $revision, $title, $calendar['timezone'], Input::json((object) $calendar['weekly']),
                    Input::json((object) $calendar['exceptions']), $actor->userId]);
            $this->audit->record($actor, $company, 'service_calendar', $id, $revision, 'service.calendar', ['code' => $code, 'timezone' => $calendar['timezone']]);
            return ['id' => $id, 'version' => $revision, 'code' => $code];
        });
    }

    public function policy(Access $actor, int $company, array $input): array
    {
        $actor->allow($company, 'manager');
        $level = Input::choice($input['service_level'], '合同服务级别', array_keys(Agreements::LEVELS));
        $effective = Input::instant($input['effective_from']);
        $id = $input['calendar_id'];
        $limits = $this->limits($input['limits']);
        return $this->db->transaction(function () use ($actor, $company, $input, $level, $effective, $id, $limits): array {
            $this->lockCompany($company);
            if (!$this->db->row('SELECT id FROM service_calendars WHERE company_id = ? AND id = ?', [$company, $id])) {
                throw new BusinessError('工作日历不在当前公司。', 404);
            }
            $latest = $this->db->row('SELECT revision,effective_from FROM service_policies WHERE company_id = ? AND service_level = ? ORDER BY revision DESC LIMIT 1', [$company, $level]);
            if ($latest !== null && $effective <= $latest['effective_from']) {
                throw new BusinessError('下一版 SLA 生效时间须晚于上一版。', 409);
            }
            $revision = ($latest['revision'] ?? 0) + 1;
            $policy = $this->db->insert('INSERT INTO service_policies (company_id,service_level,revision,effective_from,calendar_id,priority_limits,pause_for_customer,created_by)
                VALUES (?,?,?,?,?,?,?,?)', [$company, $level, $revision, $effective, $id, Input::json($limits), (int) $input['pause_for_customer'], $actor->userId]);
            $this->audit->record($actor, $company, 'service_policy', $policy, $revision, 'service.policy', ['service_level' => $level, 'calendar_id' => $id]);
            return ['id' => $policy, 'version' => $revision, 'effective_from' => $effective];
        });
    }

    public function queue(Access $actor, int $company, array $input): array
    {
        $actor->allow($company, 'manager');
        $code = Input::key($input['code'], '队列代码', 48);
        $name = Input::text($input['name'], '队列名称', 120);
        return $this->db->transaction(function () use ($actor, $company, $code, $name): array {
            $this->lockCompany($company);
            if ($this->db->row('SELECT id FROM service_queues WHERE company_id = ? AND code = ?', [$company, $code])) {
                throw new BusinessError('该公司正使用此队列代码。', 409);
            }
            $id = $this->db->insert('INSERT INTO service_queues (company_id,code,name) VALUES (?,?,?)', [$company, $code, $name]);
            $this->audit->record($actor, $company, 'service_queue', $id, 1, 'service.queue', ['code' => $code]);
            return ['id' => $id, 'code' => $code];
        });
    }

    public function route(Access $actor, int $company, array $input): array
    {
        $actor->allow($company, 'manager');
        $category = Input::choice($input['category'], '工单分类', array_keys(self::CATEGORIES));
        $queue = $input['queue_id'];
        return $this->db->transaction(function () use ($actor, $company, $category, $queue): array {
            $this->lockCompany($company);
            if (!$this->db->row('SELECT id FROM service_queues WHERE company_id = ? AND id = ?', [$company, $queue])) {
                throw new BusinessError('服务队列不在当前公司。', 404);
            }
            $this->db->run('INSERT INTO service_routes (company_id,category,queue_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE queue_id = ?',
                [$company, $category, $queue, $queue]);
            $this->audit->record($actor, $company, 'service_queue', $queue, 1, 'service.route', ['category' => $category]);
            return ['id' => $queue, 'category' => $category];
        });
    }

    public function grant(Access $actor, int $company, int $agreement, int $user, bool $allowed): array
    {
        $actor->allow($company, 'manager');
        return $this->db->transaction(function () use ($actor, $company, $agreement, $user, $allowed): array {
            $contract = (new Agreements($this->db))->subject($company, $agreement, true);
            if ($allowed) {
                $actor->eligible($company, $user, 'service');
                $this->db->run('INSERT INTO service_contract_grants (company_id,agreement_id,user_id,granted_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE granted_by = ?', [$company, $agreement, $user, $actor->userId, $actor->userId]);
            } else {
                $this->db->run('DELETE FROM service_contract_grants WHERE company_id = ? AND agreement_id = ? AND user_id = ?', [$company, $agreement, $user]);
            }
            $this->audit->record($actor, $company, 'agreement', $agreement, $contract['version'], 'service.grant', ['user_id' => $user, 'allowed' => $allowed]);
            return ['id' => $agreement, 'user_id' => $user, 'allowed' => $allowed];
        });
    }

    public function list(Access $actor, int $company): array
    {
        $actor->allow($company, 'manager');
        return [
            'calendars' => $this->db->rows('SELECT id,code,revision,name,timezone FROM service_calendars WHERE company_id = ? ORDER BY id DESC LIMIT 100', [$company]),
            'policies' => $this->db->rows('SELECT id,service_level,calendar_id,revision,effective_from,pause_for_customer FROM service_policies WHERE company_id = ? ORDER BY id DESC LIMIT 100', [$company]),
            'queues' => $this->db->rows('SELECT id,code,name FROM service_queues WHERE company_id = ? ORDER BY name,id LIMIT 100', [$company]),
            'routes' => $this->db->rows('SELECT r.category,r.queue_id,q.name FROM service_routes r JOIN service_queues q ON q.company_id = r.company_id AND q.id = r.queue_id WHERE r.company_id = ? ORDER BY r.category', [$company]),
            'grants' => $this->db->rows('SELECT g.agreement_id,g.user_id,a.reference_no,u.display_name FROM service_contract_grants g
                JOIN agreements a ON a.company_id = g.company_id AND a.id = g.agreement_id JOIN users u ON u.id = g.user_id
                WHERE g.company_id = ? ORDER BY g.granted_at DESC,g.agreement_id,g.user_id LIMIT 100', [$company]),
        ];
    }

    public function policyAt(int $company, string $level, string $time): ?array
    {
        return $this->db->row('SELECT ' . self::POLICY_COLUMNS . ' FROM service_policies p JOIN service_calendars c ON c.company_id = p.company_id AND c.id = p.calendar_id
            WHERE p.company_id = ? AND p.service_level = ? AND p.effective_from <= ? ORDER BY p.effective_from DESC LIMIT 1', [$company, $level, $time]);
    }

    public function policyById(int $company, int $id): array
    {
        return $this->db->row('SELECT ' . self::POLICY_COLUMNS . ' FROM service_policies p JOIN service_calendars c ON c.company_id = p.company_id AND c.id = p.calendar_id
            WHERE p.company_id = ? AND p.id = ?', [$company, $id]) ?? throw new \LogicException('Ticket policy is missing.');
    }

    public function queueFor(int $company, string $category): ?int
    {
        return $this->db->row('SELECT queue_id FROM service_routes WHERE company_id = ? AND category = ?', [$company, $category])['queue_id'] ?? null;
    }

    public function canWork(int $company, int $agreement, int $user, bool $lock = false): bool
    {
        return $this->db->row('SELECT user_id FROM service_contract_grants WHERE company_id = ? AND agreement_id = ? AND user_id = ?'
            . ($lock ? ' FOR SHARE' : ''), [$company, $agreement, $user]) !== null;
    }

    private function limits(array $priorityLimits): array
    {
        if (count($priorityLimits) !== count(self::PRIORITIES)) {
            throw new BusinessError('须为 P1 至 P4 分别配置响应和解决时限。');
        }
        $limits = [];
        foreach (self::PRIORITIES as $priority) {
            $submitted = $priorityLimits[$priority] ?? null;
            if (!is_array($submitted) || count($submitted) !== 2) {
                throw new BusinessError($priority . ' 须配置 response 和 resolution。');
            }
            $response = Input::integer($submitted['response'] ?? null, '响应分钟数', 1, 20160);
            $resolution = Input::integer($submitted['resolution'] ?? null, '解决分钟数', $response, 20160);
            $limits[$priority] = ['response' => $response, 'resolution' => $resolution];
        }
        return $limits;
    }

    private function lockCompany(int $company): void
    {
        $this->db->row('SELECT id FROM companies WHERE id = ? FOR UPDATE', [$company]);
    }
}
