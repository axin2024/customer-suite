<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Leads
{
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    public function claim(Access $actor, int $companyId, int $id, int $version): array
    {
        $actor->allow($companyId, 'sales');
        return $this->db->transaction(function () use ($actor, $companyId, $id, $version): array {
            $lead = $this->findRow($companyId, $id, true);
            if ($lead['owner_id'] !== null || $lead['state'] !== 'open') {
                throw new BusinessError('线索已有有效归属或已停止认领。', 409, $this->ownership($companyId, $lead));
            }
            Accounts::checkVersion($lead, $version);
            return $this->assign($actor, $companyId, $lead, $actor->userId, 'lead.claim');
        });
    }

    public function reassign(Access $actor, int $companyId, int $id, int $version, ?int $owner, string $reason): array
    {
        $actor->allow($companyId, 'manager', 'marketing');
        $reason = Input::text($reason, '分配或回收原因', 240);
        if ($owner !== null) {
            $actor->eligible($companyId, $owner, 'sales');
        }
        return $this->db->transaction(function () use ($actor, $companyId, $id, $version, $owner, $reason): array {
            $lead = $this->findRow($companyId, $id, true);
            Accounts::checkVersion($lead, $version);
            if (in_array($lead['state'], ['converted', 'closed'], true)) {
                throw new BusinessError('已转化或关闭的线索不能重分配。', 409);
            }
            return $this->assign($actor, $companyId, $lead, $owner, $owner === null ? 'lead.reclaim' : 'lead.assign', $reason);
        });
    }

    public function qualify(Access $actor, int $companyId, int $id, int $version, int $accountId, string $followUp): array
    {
        $actor->allow($companyId, 'manager', 'sales');
        $followUp = Input::date($followUp);
        return $this->db->transaction(function () use ($actor, $companyId, $id, $version, $accountId, $followUp): array {
            $account = (new Accounts($this->db))->writable($actor, $companyId, $accountId);
            if ($account['state'] !== 'active') {
                throw new BusinessError('冻结客户暂不能接收新线索。', 409);
            }
            $lead = $this->findRow($companyId, $id, true);
            $this->assertOwner($actor, $companyId, $lead);
            Accounts::checkVersion($lead, $version);
            if (!in_array($lead['state'], ['assigned', 'qualified'], true) || $lead['owner_id'] === null) {
                throw new BusinessError('请先分配待跟进线索。', 409);
            }
            $this->db->run(
                "UPDATE leads SET account_id = ?,next_follow_up = ?,state = 'qualified',version = version + 1
                 WHERE company_id = ? AND id = ?",
                [$accountId, $followUp, $companyId, $id],
            );
            $this->audit->record($actor, $companyId, 'lead', $id, $version + 1, 'lead.qualify',
                ['account_id' => $accountId, 'next_follow_up' => $followUp]);
            return ['id' => $id, 'version' => $version + 1, 'account_id' => $accountId];
        });
    }

    public function list(Access $actor, int $companyId, string $state = 'open', ?string $cursor = null): array
    {
        $actor->allow($companyId, 'manager', 'marketing', 'sales');
        $state = Input::choice($state, '线索状态', ['open', 'assigned', 'qualified', 'converted', 'closed']);
        $own = !$actor->has($companyId, 'manager', 'marketing') && $state !== 'open';
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT id,name,state,owner_id,account_id,next_follow_up,version,updated_at FROM leads
             WHERE company_id = ? AND state = ?' . ($own ? ' AND owner_id = ?' : '') . $page->clause() .
            ' ORDER BY updated_at DESC,id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$companyId, $state, ...($own ? [$actor->userId] : []), ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $companyId, int $id, int $after = 0): array
    {
        $actor->allow($companyId, 'manager', 'marketing', 'sales');
        $lead = $this->findRow($companyId, $id);
        $manager = $actor->has($companyId, 'manager');
        $marketing = $actor->has($companyId, 'marketing');
        $owner = $lead['owner_id'] !== null && (int) $lead['owner_id'] === $actor->userId;
        if (!$manager && !$marketing && !$owner && $lead['state'] !== 'open') {
            throw new BusinessError('未找到线索。', 404);
        }
        if (!$manager && !$owner) {
            $lead['email'] = $lead['email'] === null ? null : '•••@' . substr(strrchr($lead['email'], '@'), 1);
            $lead['phone'] = $lead['phone'] === null ? null : '•••' . substr($lead['phone'], -4);
        }
        $lead['touches'] = $this->db->rows(
            'SELECT t.id,t.campaign_id,c.name AS campaign,t.source,t.external_id,t.occurred_at
             FROM lead_touches t JOIN campaigns c ON c.company_id = t.company_id AND c.id = t.campaign_id
             WHERE t.company_id = ? AND t.lead_id = ? AND t.id > ? ORDER BY t.id LIMIT 100',
            [$companyId, $id, $after],
        );
        $lead['first_touch'] = $this->touchBoundary($companyId, $id, false);
        $lead['recent_touch'] = $this->touchBoundary($companyId, $id, true);
        $lead['timeline'] = $this->audit->timeline($companyId, 'lead', $id);
        return $lead;
    }

    public function forConversion(Access $actor, int $companyId, int $id, int $version, int $accountId): array
    {
        $actor->allow($companyId, 'manager', 'sales');
        $lead = $this->findRow($companyId, $id, true);
        $this->assertOwner($actor, $companyId, $lead);
        Accounts::checkVersion($lead, $version);
        if ($lead['state'] !== 'qualified' || (int) $lead['account_id'] !== $accountId) {
            throw new BusinessError('线索须已完成资格确认并属于同一客户。', 409);
        }
        return $lead;
    }

    private function touchBoundary(int $companyId, int $leadId, bool $newest): ?array
    {
        return $this->db->row(
            'SELECT id,campaign_id,occurred_at FROM lead_touches WHERE company_id = ? AND lead_id = ?
             ORDER BY occurred_at ' . ($newest ? 'DESC,id DESC' : 'ASC,id ASC') . ' LIMIT 1',
            [$companyId, $leadId],
        );
    }

    private function findRow(int $companyId, int $leadId, bool $lock = false): array
    {
        return $this->db->row(
            'SELECT * FROM leads WHERE company_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$companyId, $leadId],
        ) ?? throw new BusinessError('未找到线索。', 404);
    }

    private function assertOwner(Access $actor, int $companyId, array $lead): void
    {
        if (!$actor->has($companyId, 'manager') && (int) $lead['owner_id'] !== $actor->userId) {
            throw new BusinessError('仅有效负责人可以更新线索。', 403);
        }
    }

    private function assign(Access $actor, int $companyId, array $lead, ?int $owner, string $action, ?string $reason = null): array
    {
        $version = (int) $lead['version'] + 1;
        $id = (int) $lead['id'];
        $state = $owner === null ? 'open' : 'assigned';
        $this->db->run(
            'UPDATE leads SET owner_id = ?,state = ?,account_id = NULL,next_follow_up = NULL,version = ?
             WHERE company_id = ? AND id = ?',
            [$owner, $state, $version, $companyId, $id],
        );
        $this->audit->record($actor, $companyId, 'lead', $id, $version, $action,
            ['previous_owner_id' => $lead['owner_id'], 'owner_id' => $owner, 'reason' => $reason]);
        return ['id' => $id, 'version' => $version, 'owner_id' => $owner, 'state' => $state];
    }

    private function ownership(int $companyId, array $lead): array
    {
        $owner = $lead['owner_id'] === null ? null : $this->db->row(
            'SELECT u.id,u.display_name FROM users u WHERE u.id = ? AND
             (u.is_admin = 1 OR EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.company_id = ?))',
            [$lead['owner_id'], $companyId],
        );
        return ['owner' => $owner, 'current_version' => (int) $lead['version']];
    }
}
