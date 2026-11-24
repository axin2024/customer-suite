<?php

declare(strict_types=1);

namespace CustomerSuite;

final class ServiceTickets
{
    public const STATES = ['open', 'review', 'waiting', 'resolved', 'closed'];
    public const REVIEW_REASONS = [
        'expired' => '合同已到期', 'not_started' => '合同尚未生效', 'ended' => '合同已终止',
        'missing_policy' => '缺少该服务级别的 SLA 规则', 'unrouted' => '工单分类尚未配置队列',
    ];

    private readonly ServiceSettings $settings;
    private readonly SlaClocks $clocks;
    private readonly Agreements $agreements;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->settings = new ServiceSettings($db);
        $this->clocks = new SlaClocks($db);
        $this->agreements = new Agreements($db);
        $this->audit = new Audit($db);
    }

    public function receive(Access $actor, int $company, array $data): array
    {
        $actor->allow($company, 'manager', 'service');
        $category = Input::choice($data['category'], '服务分类', array_keys(ServiceSettings::CATEGORIES));
        $priority = Input::choice($data['priority'], '工单优先级', ServiceSettings::PRIORITIES);
        $title = Input::text($data['title'], '工单标题', 180);
        $description = Input::text($data['description'], '工单说明', 3000);
        $date = $this->eventTime($data['occurred_at']);
        $source = Input::key($data['source'], '工单来源', 64);
        $external = Input::key($data['external_id'], '外部编号', 128);
        return $this->db->transaction(function () use ($actor, $company, $data, $category, $priority, $title, $description, $date, $source, $external): array {
            $contract = $this->agreements->subject($company, $data['agreement_id'], true);
            $this->contractAccess($actor, $company, $contract['id'], true);
            $keys = [$company, $source, $external];
            $digest = Input::digest([$contract['id'], $category, $priority, $title, $description, $date]);
            $this->db->run('INSERT INTO service_events (company_id,source,external_id,payload_digest,received_by,occurred_at)
                VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_digest = payload_digest', [...$keys, $digest, $actor->userId, $date]);
            $event = $this->db->row('SELECT payload_digest,result FROM service_events WHERE company_id = ? AND source = ? AND external_id = ? FOR UPDATE', $keys);
            if (!hash_equals($event['payload_digest'], $digest)) {
                throw new BusinessError('同一工单来源编号已绑定不同内容。', 409);
            }
            if ($event['result'] !== null) {
                return json_decode($event['result'], true, 16, JSON_THROW_ON_ERROR);
            }
            $signed = $this->signedAt($contract, $date);
            [$reason, $queue, $policy] = $this->eligibility($contract, $signed, $category, $date);
            $state = $reason === null ? 'open' : 'review';
            $id = $this->db->insert('INSERT INTO service_tickets (company_id,account_id,agreement_id,agreement_revision,queue_id,requested_by,
                policy_id,title,description,category,priority,state,review_reason,received_at,last_event_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$company, $contract['account_id'], $contract['id'], $signed['revision'], $queue, $actor->userId, $policy['id'] ?? null,
                    $title, $description, $category, $priority, $state, $reason, $date, $date]);
            if ($reason === null) {
                $this->clocks->start($company, $id, $priority, $policy, $date);
            }
            $result = $this->result($this->row($company, $id), $date);
            $this->db->run('UPDATE service_events SET ticket_id = ?,result = ? WHERE company_id = ? AND source = ? AND external_id = ?',
                [$id, Input::json($result), ...$keys]);
            $this->audit->record($actor, $company, 'ticket', $id, 1, 'ticket.receive',
                ['source' => $source, 'external_id' => $external, 'agreement_revision' => $signed['revision'], 'review_reason' => $reason, 'queue_id' => $queue]);
            return $result;
        });
    }

    public function review(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $actor->allow($company, 'manager');
        $decision = Input::choice($data['decision'], '资格复核决定', ['recheck', 'close']);
        $basis = Input::text($data['reason'], '资格复核依据', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $decision, $basis): array {
            $old = $this->row($company, $id);
            $contract = $this->agreements->subject($company, $old['agreement_id'], true);
            $ticket = $this->row($company, $id, true);
            Accounts::checkVersion($ticket, $version);
            if ($ticket['state'] !== 'review') {
                throw new BusinessError('工单不在待复核列表中。', 409);
            }
            $at = $this->ticketTime($ticket, null);
            if ($decision === 'close') {
                $this->db->run("UPDATE service_tickets SET state = 'closed',closed_at = ?,close_reason = ?,last_event_at = ? WHERE company_id = ? AND id = ?",
                    [$at, $basis, $at, $company, $id]);
            } else {
                $signed = $this->signedAt($contract, $at);
                [$reason, $queue, $policy] = $this->eligibility($contract, $signed, $ticket['category'], $at);
                if ($reason !== null) {
                    throw new BusinessError('尚未具备服务资格，来单继续保留待复核。', 409, ['review_reason' => $reason]);
                }
                $this->db->run("UPDATE service_tickets SET state = 'open',queue_id = ?,policy_id = ?,agreement_revision = ?,review_reason = NULL,last_event_at = ?
                    WHERE company_id = ? AND id = ?", [$queue, $policy['id'], $signed['revision'], $at, $company, $id]);
                $this->clocks->start($company, $id, $ticket['priority'], $policy, $at);
            }
            return $this->record($actor, $ticket, 'ticket.review', $at, ['decision' => $decision, 'reason' => $basis, 'prior_reason' => $ticket['review_reason']]);
        });
    }

    public function assign(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $reason = Input::text($data['reason'], '派发依据', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $data, $reason): array {
            $ticket = $this->writable($actor, $company, $id, $version);
            $owner = $data['owner_id'] ?? null;
            $queue = $data['queue_id'] ?? $ticket['queue_id'];
            if (!in_array($ticket['state'], ['open', 'waiting'], true)) {
                throw new BusinessError('只有受理中的工单可派发。', 409);
            }
            if (!$actor->has($company, 'manager') && ($owner !== $actor->userId || isset($data['queue_id']))) {
                throw new BusinessError('服务人员仅可认领本人办理的工单。', 403);
            }
            if (isset($data['queue_id']) && !$this->db->row('SELECT id FROM service_queues WHERE company_id = ? AND id = ?', [$company, $queue])) {
                throw new BusinessError('未找到当前公司队列。', 404);
            }
            if ($owner !== null) {
                $actor->eligible($company, $owner, 'service');
                if (!$this->settings->canWork($company, $ticket['agreement_id'], $owner, true)) {
                    throw new BusinessError('负责人须有对应服务合同授权。');
                }
            }
            $at = $this->ticketTime($ticket, null);
            $this->db->run('UPDATE service_tickets SET owner_id = ?,queue_id = ?,last_event_at = ? WHERE company_id = ? AND id = ?', [$owner, $queue, $at, $company, $id]);
            return $this->record($actor, $ticket, 'ticket.assign', $at, ['owner_id' => $owner, 'prior_owner_id' => $ticket['owner_id'], 'reason' => $reason]);
        });
    }

    public function note(Access $actor, int $company, int $id, int $version, string $body): array
    {
        $body = Input::text($body, '工单记录', 2000);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $body): array {
            $ticket = $this->writable($actor, $company, $id, $version);
            if ($ticket['state'] === 'closed') {
                throw new BusinessError('已关闭工单只可查阅。', 409);
            }
            $note = $this->db->insert('INSERT INTO ticket_notes (company_id,ticket_id,actor_id,body) VALUES (?,?,?,?)', [$company, $id, $actor->userId, $body]);
            return $this->record($actor, $ticket, 'ticket.note', $this->now(), ['note_id' => $note]);
        });
    }

    public function advance(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $action = Input::choice($data['action'], '工单操作', ['respond', 'wait', 'resume', 'resolve', 'close']);
        $reason = Input::text($data['reason'], '办理记录', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $action, $reason, $data): array {
            $ticket = $this->writable($actor, $company, $id, $version);
            $at = $this->ticketTime($ticket, $data['occurred_at'] ?? null);
            $running = in_array($ticket['state'], ['open', 'waiting'], true);
            $update = match (true) {
                $action === 'respond' && $running && $ticket['responded_at'] === null => ['responded_at = ?', $at],
                $action === 'wait' && $ticket['state'] === 'open' => ["state = 'waiting',wait_reason = ?", $reason],
                $action === 'resume' && $ticket['state'] === 'waiting' => ["state = 'open',wait_reason = ?", null],
                $action === 'resolve' && $running && $ticket['responded_at'] !== null => ["state = 'resolved',wait_reason = NULL,resolved_at = ?", $at],
                $action === 'close' && $ticket['state'] === 'resolved' => ["state = 'closed',close_reason = ?,closed_at = ?", $reason, $at],
                default => throw new BusinessError('当前状态不允许此工单操作。', 409),
            };
            $columns = array_shift($update);
            if ($action !== 'close') {
                $this->escalate($actor, $ticket, $at);
                $policy = $this->settings->policyById($company, $ticket['policy_id']);
                $this->clocks->change($ticket, $policy, $action, $at);
            }
            $this->db->run('UPDATE service_tickets SET ' . $columns . ',last_event_at = ? WHERE company_id = ? AND id = ?', [...$update, $at, $company, $id]);
            return $this->record($actor, $ticket, 'ticket.' . $action, $at, ['reason' => $reason]);
        });
    }

    public function sweep(Access $actor, int $company, ?string $occurredAt = null): array
    {
        $actor->allow($company, 'manager');
        $at = $this->eventTime($occurredAt);
        $due = $this->db->rows('SELECT DISTINCT s.ticket_id FROM sla_clocks s JOIN service_tickets t ON t.company_id = s.company_id AND t.id = s.ticket_id
            WHERE s.company_id = ? AND s.deadline_at <= ? AND t.escalation_level = 0 ORDER BY s.ticket_id LIMIT 100', [$company, $at]);
        $escalated = [];
        foreach ($due as $item) {
            $result = $this->db->transaction(function () use ($actor, $company, $item, $at): ?array {
                $ticket = $this->row($company, $item['ticket_id'], true);
                if (!$this->escalate($actor, $ticket, $at)) {
                    return null;
                }
                return $this->record($actor, $ticket, 'ticket.sweep', $at, []);
            });
            if ($result !== null) {
                $escalated[] = ['id' => $result['id'], 'version' => $result['version']];
            }
        }
        return ['escalated' => $escalated, 'count' => count($escalated), 'checked_at' => $at];
    }

    public function list(Access $actor, int $company, string $state, ?int $queue, ?string $cursor): array
    {
        $actor->allow($company, 'manager', 'service');
        Input::choice($state, '工单状态', self::STATES);
        $page = WorklistPage::read($cursor);
        $manager = $actor->has($company, 'manager');
        return WorklistPage::result($this->db->rows(
            'SELECT t.id,t.title,t.state,t.priority,t.category,t.owner_id,t.version,t.updated_at,t.review_reason,t.escalation_level,q.name AS queue_name
             FROM service_tickets t LEFT JOIN service_queues q ON q.company_id = t.company_id AND q.id = t.queue_id
             WHERE t.company_id = ? AND t.state = ?' . ($queue === null ? '' : ' AND t.queue_id = ?')
                . ($manager ? '' : ' AND EXISTS (SELECT 1 FROM service_contract_grants g WHERE g.company_id = t.company_id AND g.agreement_id = t.agreement_id AND g.user_id = ?)')
                . $page->clause('t') . ' ORDER BY t.updated_at DESC,t.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, $state, ...($queue === null ? [] : [$queue]), ...($manager ? [] : [$actor->userId]), ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id, int $after, ?string $observedAt): array
    {
        $ticket = $this->readable($actor, $company, $id);
        $at = max($ticket['last_event_at'], $this->eventTime($observedAt));
        $ticket['can_edit'] = $actor->has($company, 'manager') || $ticket['owner_id'] === null || $ticket['owner_id'] === $actor->userId;
        $ticket['timeline'] = $this->audit->timeline($company, 'ticket', $id, $after);
        $ticket['notes'] = $this->db->rows('SELECT n.id,n.body,n.created_at,u.display_name FROM ticket_notes n JOIN users u ON u.id = n.actor_id
            WHERE n.company_id = ? AND n.ticket_id = ? AND n.id > ? ORDER BY n.id LIMIT 100', [$company, $id, $after]);
        $ticket['escalation'] = $this->db->row('SELECT clock_kind,missed_deadline,detected_at,detected_by FROM service_escalations WHERE company_id = ? AND ticket_id = ?', [$company, $id]);
        $ticket['source'] = $this->db->row('SELECT source,external_id,occurred_at FROM service_events WHERE company_id = ? AND ticket_id = ?', [$company, $id]);
        return $ticket + $this->result($ticket, $at);
    }

    public function readable(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'service');
        $ticket = $this->row($company, $id);
        $this->contractAccess($actor, $company, $ticket['agreement_id']);
        return $ticket;
    }

    private function eligibility(array $contract, array $revision, string $category, string $at): array
    {
        $company = $contract['company_id'];
        $queue = $this->settings->queueFor($company, $category);
        $date = substr($at, 0, 10);
        $policy = $this->settings->policyAt($company, $revision['service_level'], $at);
        $reason = match (true) {
            $contract['state'] === 'ended' => 'ended',
            $date > $revision['ends_on'] => 'expired',
            $date < $revision['starts_on'] || $at < $revision['decided_at'] => 'not_started',
            $queue === null => 'unrouted',
            $policy === null => 'missing_policy',
            default => null,
        };
        return [$reason, $queue, $reason === null ? $policy : null];
    }

    private function signedAt(array $contract, string $at): array
    {
        return $this->db->row("SELECT * FROM agreement_versions WHERE company_id = ? AND agreement_id = ? AND decision = 'signed'
            AND decided_at <= ? ORDER BY revision DESC LIMIT 1", [$contract['company_id'], $contract['id'], $at])
            ?? $this->agreements->revision($contract['company_id'], $contract['id'], 1);
    }

    private function contractAccess(Access $actor, int $company, int $agreementId, bool $lock = false): void
    {
        if (!$actor->has($company, 'manager') && !$this->settings->canWork($company, $agreementId, $actor->userId, $lock)) {
            throw new BusinessError('未找到当前公司已授权的服务记录。', 404);
        }
    }

    private function writable(Access $actor, int $company, int $id, int $version): array
    {
        $actor->allow($company, 'manager', 'service');
        $ticket = $this->row($company, $id, true);
        $this->contractAccess($actor, $company, $ticket['agreement_id'], true);
        if (!$actor->has($company, 'manager') && $ticket['owner_id'] !== null && $ticket['owner_id'] !== $actor->userId) {
            throw new BusinessError('工单由其他服务人员办理。', 403);
        }
        Accounts::checkVersion($ticket, $version);
        return $ticket;
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        $query = $lock ? 'SELECT t.* FROM service_tickets t' : 'SELECT t.*,q.name AS queue_name,a.reference_no FROM service_tickets t
            LEFT JOIN service_queues q ON q.company_id = t.company_id AND q.id = t.queue_id
            JOIN agreements a ON a.company_id = t.company_id AND a.id = t.agreement_id';
        return $this->db->row($query . ' WHERE t.company_id = ? AND t.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到工单。', 404);
    }

    private function escalate(Access $actor, array $ticket, string $at): bool
    {
        if ($ticket['escalation_level'] !== 0 || $at < $ticket['last_event_at']) {
            return false;
        }
        $company = $ticket['company_id'];
        $id = $ticket['id'];
        $due = $this->clocks->overdue($company, $id, $at);
        if ($due === null) {
            return false;
        }
        $this->db->run('INSERT INTO service_escalations (company_id,ticket_id,clock_kind,missed_deadline,detected_at,detected_by)
            VALUES (?,?,?,?,?,?)', [$company, $id, $due['kind'], $due['deadline_at'], $at, $actor->userId]);
        $this->db->run('UPDATE service_tickets SET escalation_level = 1 WHERE company_id = ? AND id = ?', [$company, $id]);
        $this->audit->record($actor, $company, 'ticket', $id, $ticket['version'] + 1, 'ticket.escalate', ['clock_kind' => $due['kind'], 'due_at' => $due['deadline_at']]);
        return true;
    }

    private function record(Access $actor, array $ticket, string $action, string $at, array $details): array
    {
        $version = $ticket['version'] + 1;
        $company = $ticket['company_id'];
        $id = $ticket['id'];
        $this->db->run('UPDATE service_tickets SET version = ? WHERE company_id = ? AND id = ?', [$version, $company, $id]);
        $this->audit->record($actor, $company, 'ticket', $id, $version, $action, $details + ['event_at' => $at]);
        return $this->result($this->row($company, $id), $at);
    }

    private function result(array $ticket, string $at): array
    {
        $policy = $ticket['policy_id'] === null ? null : $this->settings->policyById($ticket['company_id'], $ticket['policy_id']);
        return [
            'id' => $ticket['id'], 'version' => $ticket['version'], 'state' => $ticket['state'], 'queue_id' => $ticket['queue_id'],
            'queue_name' => $ticket['queue_name'], 'agreement_revision' => $ticket['agreement_revision'],
            'review_reason' => $ticket['review_reason'], 'escalation_level' => $ticket['escalation_level'],
            'pause_reason' => $ticket['state'] === 'waiting' && (bool) ($policy['pause_for_customer'] ?? false) ? $ticket['wait_reason'] : null,
            'calendar' => $policy === null ? null : ['id' => $policy['calendar_id'], 'revision' => $policy['calendar_revision'], 'timezone' => $policy['timezone']],
            'policy_revision' => $policy['revision'] ?? null,
            'clocks' => $policy === null ? [] : $this->clocks->summary($ticket['company_id'], $ticket['id'], $policy, $at),
        ];
    }

    private function now(): string
    {
        return $this->db->row('SELECT UTC_TIMESTAMP(6) AS now')['now'];
    }

    private function eventTime(?string $input): string
    {
        if ($input === null) {
            return $this->now();
        }
        $time = Input::instant($input);
        if ($time > $this->db->row('SELECT UTC_TIMESTAMP(6) + INTERVAL 5 MINUTE AS latest')['latest']) {
            throw new BusinessError('事件时间不得超前当前时间。');
        }
        return $time;
    }

    private function ticketTime(array $ticket, ?string $input): string
    {
        $at = $this->eventTime($input);
        if ($input === null) {
            return max($at, $ticket['last_event_at']);
        }
        if ($at < $ticket['last_event_at']) {
            throw new BusinessError('事件时间早于最后已记录的工单事件。', 409, ['last_event_at' => $ticket['last_event_at']]);
        }
        return $at;
    }
}
