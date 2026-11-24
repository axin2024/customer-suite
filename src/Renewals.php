<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Renewals
{
    public const STATES = ['scheduled', 'working', 'renewed', 'lost', 'superseded'];
    public const OUTCOMES = [
        'renewed' => ['signed' => '已签续约'],
        'lost' => ['price' => '价格或预算', 'product' => '产品或服务', 'competitor' => '转用竞品', 'customer_closed' => '客户停业', 'other' => '其他原因'],
    ];
    private const PAGE = 50;

    private readonly SuccessContracts $contracts;
    private readonly Audit $audit;
    private readonly Opportunities $opportunities;

    public function __construct(private readonly Database $db)
    {
        $this->contracts = new SuccessContracts($db);
        $this->audit = new Audit($db);
        $this->opportunities = new Opportunities($db);
    }

    public function scan(Access $actor, int $company, array $data): array
    {
        $actor->allow($company, 'manager');
        $from = Input::date($data['as_of'] ?? gmdate('Y-m-d'));
        $days = Input::integer($data['window_days'] ?? 30, '续约窗口天数', 1, 365);
        $through = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->modify("+$days days")->format('Y-m-d');
        Input::date($through);
        return $this->db->transaction(function () use ($actor, $company, $from, $through): array {
            $candidates = $this->db->rows(
                "SELECT a.id FROM agreements a JOIN agreement_versions v
                     ON v.company_id = a.company_id AND v.agreement_id = a.id AND v.revision = a.active_revision
                 JOIN success_assignments s ON s.company_id = a.company_id AND s.agreement_id = a.id
                 JOIN users u ON u.id = s.owner_id AND u.active = 1
                 WHERE a.company_id = ? AND a.state <> 'ended' AND v.ends_on BETWEEN ? AND ?
                     AND (u.is_admin = 1 OR EXISTS (
                         SELECT 1 FROM memberships m WHERE m.company_id = a.company_id AND m.user_id = u.id AND m.role = 'success'))
                     AND NOT EXISTS (SELECT 1 FROM renewals r WHERE r.company_id = a.company_id AND r.agreement_id = a.id
                         AND r.cycle_ends_on = v.ends_on)
                 ORDER BY a.id LIMIT " . (self::PAGE + 1), [$company, $from, $through],
            );
            $more = count($candidates) > self::PAGE;
            $created = [];
            foreach (array_slice($candidates, 0, self::PAGE) as $candidate) {
                $result = $this->schedule($actor, $company, $candidate['id'], $from, $through);
                if ($result !== null) {
                    $created[] = $result;
                }
            }
            return ['as_of' => $from, 'through' => $through, 'created_count' => count($created), 'items' => $created, 'more' => $more];
        });
    }

    private function schedule(Access $actor, int $company, int $id, string $from, string $through): ?array
    {
        [$contract, $account, $owner] = $this->contracts->lock($actor, $company, $id);
        $revision = (new Agreements($this->db))->revision($company, $id, $contract['active_revision']);
        $end = $revision['ends_on'];
        if ($end < $from || $end > $through || $contract['state'] === 'ended' || $this->db->row(
            'SELECT id FROM renewals WHERE company_id = ? AND agreement_id = ? AND cycle_ends_on = ?', [$company, $id, $end],
        )) {
            return null;
        }
        try {
            $actor->eligible($company, $owner, 'success');
        } catch (BusinessError $error) {
            if ($error->status !== 422) {
                throw $error;
            }
            return null;
        }
        $previous = $this->db->rows(
            "SELECT * FROM renewals WHERE company_id = ? AND agreement_id = ? AND state IN ('scheduled','working') ORDER BY id FOR UPDATE",
            [$company, $id],
        );
        foreach ($previous as $renewal) {
            $this->close($actor, $company, $renewal, 'superseded', 'cycle_changed', '新合同到期日为 ' . $end . '。', null);
        }
        $opportunity = $this->opportunities->insert($actor, $company, $account['id'], $account['owner_id'],
            '续约 · ' . $contract['reference_no'] . ' · ' . $end, $revision['currency'], $revision['value_minor'], $end);
        $renewalId = $this->db->insert(
            'INSERT INTO renewals (company_id,agreement_id,agreement_revision,opportunity_id,owner_id,cycle_ends_on,next_follow_up)
             VALUES (?,?,?,?,?,?,?)', [$company, $id, $revision['revision'], $opportunity, $owner, $end, $from],
        );
        $result = ['id' => $renewalId, 'version' => 1, 'opportunity_id' => $opportunity, 'agreement_id' => $id,
            'agreement_revision' => $revision['revision'], 'owner_id' => $owner, 'cycle_ends_on' => $end, 'state' => 'scheduled'];
        $this->audit->record($actor, $company, 'renewal', $renewalId, 1, 'renewal.schedule', $result);
        $this->audit->record($actor, $company, 'agreement', $id, $contract['version'], 'renewal.schedule',
            ['renewal_id' => $renewalId, 'opportunity_id' => $opportunity, 'cycle_ends_on' => $end]);
        return $result;
    }

    public function followUp(Access $actor, int $company, array $data): array
    {
        $on = Input::date($data['next_follow_up']);
        $reason = Input::text($data['reason'], '跟进记录', 400);
        return $this->db->transaction(function () use ($actor, $company, $data, $on, $reason): array {
            [$renewal, $contract] = $this->lock($actor, $company, $data['id']);
            Accounts::checkVersion($renewal, $data['version']);
            if (!$this->isCurrent($company, $renewal, $contract)) {
                throw new BusinessError('续约已结束或合同到期日已变化，请刷新续约工作台。', 409);
            }
            $this->db->run(
                "UPDATE renewals SET state = 'working',next_follow_up = ?,version = version + 1 WHERE company_id = ? AND id = ?",
                [$on, $company, $renewal['id']],
            );
            $version = $renewal['version'] + 1;
            $this->audit->record($actor, $company, 'renewal', $renewal['id'], $version, 'renewal.follow-up',
                ['next_follow_up' => $on, 'reason' => $reason]);
            return ['id' => $renewal['id'], 'agreement_id' => $renewal['agreement_id'], 'version' => $version, 'state' => 'working'];
        });
    }

    public function decide(Access $actor, int $company, array $data): array
    {
        $state = Input::choice($data['outcome'], '续约结果', array_keys(self::OUTCOMES));
        $code = Input::choice($data['code'], '结果分类', array_keys(self::OUTCOMES[$state]));
        $reason = Input::text($data['reason'], '结果说明', 240);
        $evidence = Input::optionalText($data['evidence_ref'] ?? null, '签署凭据编号', 160);
        if ($state === 'renewed' && $evidence === null) {
            throw new BusinessError('登记续约成功须填签署凭据编号。');
        }
        return $this->db->transaction(function () use ($actor, $company, $data, $state, $code, $reason, $evidence): array {
            [$renewal, $contract] = $this->lock($actor, $company, $data['id']);
            Accounts::checkVersion($renewal, $data['version']);
            if (!$this->isCurrent($company, $renewal, $contract)) {
                throw new BusinessError('续约已结束或合同到期日已变化，请刷新续约工作台。', 409);
            }
            return $this->close($actor, $company, $renewal, $state, $code, $reason, $evidence);
        });
    }

    private function close(Access $actor, int $company, array $renewal, string $state, string $code, string $reason, ?string $evidence): array
    {
        $id = $renewal['id'];
        $version = $renewal['version'] + 1;
        $this->opportunities->finishRenewal($actor, $company, $renewal['opportunity_id'], $id, $state === 'renewed', $reason);
        $this->db->run(
            'UPDATE renewals SET state = ?,outcome_code = ?,outcome_reason = ?,evidence_ref = ?,next_follow_up = NULL,
             decided_by = ?,decided_at = UTC_TIMESTAMP(6),version = ? WHERE company_id = ? AND id = ?',
            [$state, $code, $reason, $evidence, $actor->userId, $version, $company, $id],
        );
        $result = ['id' => $id, 'version' => $version, 'agreement_id' => $renewal['agreement_id'],
            'opportunity_id' => $renewal['opportunity_id'], 'state' => $state, 'outcome_code' => $code];
        $this->audit->record($actor, $company, 'renewal', $id, $version, 'renewal.close', $result + ['reason' => $reason, 'evidence_ref' => $evidence]);
        return $result;
    }

    public function list(Access $actor, int $company, ?string $state, ?string $cursor, ?int $agreementId = null): array
    {
        $actor->allow($company, 'manager', 'success');
        $manager = $actor->has($company, 'manager');
        if ($state !== null) {
            Input::choice($state, '续约状态', self::STATES);
        }
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT r.id,r.agreement_id,r.agreement_revision,r.opportunity_id,r.owner_id,r.state,r.cycle_ends_on,
                r.next_follow_up,r.updated_at,r.version,a.reference_no,a.legal_name
             FROM renewals r JOIN agreements a ON a.company_id = r.company_id AND a.id = r.agreement_id
             JOIN success_assignments s ON s.company_id = r.company_id AND s.agreement_id = r.agreement_id
             WHERE r.company_id = ?' . ($manager ? '' : ' AND s.owner_id = ?') .
            ($state === null ? '' : ' AND r.state = ?') . ($agreementId === null ? '' : ' AND r.agreement_id = ?') . $page->clause('r') .
             ' ORDER BY r.updated_at DESC,r.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, ...($manager ? [] : [$actor->userId]), ...($state === null ? [] : [$state]),
                ...($agreementId === null ? [] : [$agreementId]), ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id, int $after): array
    {
        $renewal = $this->readable($actor, $company, $id);
        $contract = (new Agreements($this->db))->subject($company, $renewal['agreement_id']);
        $renewal['reference_no'] = $contract['reference_no'];
        $renewal['legal_name'] = $contract['legal_name'];
        $renewal['is_current'] = $this->isCurrent($company, $renewal, $contract);
        $renewal['timeline'] = $this->audit->timeline($company, 'renewal', $id, $after);
        return $renewal;
    }

    public function readable(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'success');
        $renewal = $this->row($company, $id);
        $this->contracts->readable($actor, $company, $renewal['agreement_id']);
        return $renewal;
    }

    public function lock(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'success');
        $renewal = $this->row($company, $id);
        [$contract, $account] = $this->contracts->lock($actor, $company, $renewal['agreement_id']);
        return [$this->row($company, $id, true), $contract, $account];
    }

    public function isCurrent(int $company, array $renewal, array $contract): bool
    {
        return in_array($renewal['state'], ['scheduled', 'working'], true) && $contract['state'] !== 'ended'
            && $renewal['cycle_ends_on'] === (new Agreements($this->db))->revision($company, $contract['id'], $contract['active_revision'])['ends_on'];
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        return $this->db->row('SELECT * FROM renewals WHERE company_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到续约记录。', 404);
    }
}
