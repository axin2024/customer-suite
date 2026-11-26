<?php

declare(strict_types=1);

namespace CustomerSuite;

final class ReviewDelegations
{
    public function __construct(private readonly Database $db)
    {
    }

    public function change(Access $actor, int $company, string $action, array $data): array
    {
        $actor->allow($company, 'manager', 'approver');
        $kind = Input::choice($data['kind'], '单据类型', ['quote', 'agreement']);
        $id = $data['id'];
        return $this->db->transaction(function () use ($actor, $company, $action, $kind, $id, $data): array {
            $subject = $this->pending($company, $kind, $id);
            Accounts::checkVersion($subject, $data['version']);
            $revision = $subject['revision'];
            $row = $this->assignment($company, $kind, $id, $revision);
            if ($action === 'review.revoke') {
                if ($row === null || $row['state'] !== 'assigned') {
                    throw new BusinessError('当前没有可撤回的审批委托。', 409);
                }
                if ($row['granted_by'] !== $actor->userId && !$actor->has($company, 'manager')) {
                    throw new BusinessError('仅委托人或本公司经理可撤回。', 403);
                }
                if ($row['delegation_version'] !== $data['delegation_version']) {
                    throw new BusinessError('审批委托已更新，请刷新当前版本。', 409);
                }
                $this->setState($company, $kind, $id, $revision, 'revoked');
                $delegate = $row['delegate_id'];
                $grantor = $row['granted_by'];
                $state = 'revoked';
            } else {
                $delegate = $data['delegate_id'];
                $grantor = $actor->userId;
                $hours = Input::integer($data['hours'], '委托有效小时数', 1, 168);
                if ($grantor === $delegate || in_array($grantor, $subject['authors'], true) || in_array($delegate, $subject['authors'], true)) {
                    throw new BusinessError('委托人和代办人须为不同的独立审批人。', 403);
                }
                if (!$this->eligible($company, $delegate, true)) {
                    throw new BusinessError('代办人须具有本公司的审批权限。');
                }
                if ($row !== null && $this->active($company, $row)) {
                    throw new BusinessError('已有有效委托，请先撤回。', 409);
                }
                $this->db->run(
                    "INSERT INTO review_delegations (company_id,kind,object_id,revision,granted_by,delegate_id,expires_at)
                     VALUES (?,?,?,?,?,?,TIMESTAMPADD(HOUR,?,UTC_TIMESTAMP(6)))
                     ON DUPLICATE KEY UPDATE granted_by = ?,delegate_id = ?,expires_at = TIMESTAMPADD(HOUR,?,UTC_TIMESTAMP(6)),
                         state = 'assigned',delegation_version = delegation_version + 1",
                    [$company, $kind, $id, $revision, $grantor, $delegate, $hours, $grantor, $delegate, $hours],
                );
                $state = 'assigned';
            }
            $result = ['id' => $id, 'kind' => $kind, 'revision' => $revision, 'delegate_id' => $delegate, 'granted_by' => $grantor,
                'delegation_version' => ($row['delegation_version'] ?? 0) + ($state === 'assigned' ? 1 : 0), 'state' => $state];
            (new Audit($this->db))->record($actor, $company, $kind, $id, $subject['version'], $action, $result);
            return $result;
        });
    }

    // The caller holds the quotation or agreement lock and records the decision in the same transaction.
    public function decide(Access $actor, int $company, string $kind, int $id, int $revision, ?int $delegationVersion): array
    {
        $row = $this->assignment($company, $kind, $id, $revision);
        $delegated = $delegationVersion !== null;
        if ($delegated && ($row === null || $row['delegate_id'] !== $actor->userId
            || $row['delegation_version'] !== $delegationVersion || !$this->active($company, $row, true))) {
            throw new BusinessError('该版本没有可由你执行的有效委托。', 403);
        }
        if ($row !== null && $row['state'] === 'assigned') {
            $this->setState($company, $kind, $id, $revision, $delegated ? 'used' : 'superseded');
        }
        return $delegated ? ['delegated_by' => $row['granted_by'], 'delegation_version' => $delegationVersion] : [];
    }

    public function summary(Access $actor, int $company, string $kind, int $id, int $revision, bool $canReview): ?array
    {
        $row = $this->assignment($company, $kind, $id, $revision);
        if ($row === null || (!$canReview && $row['delegate_id'] !== $actor->userId && $row['granted_by'] !== $actor->userId
            && !$actor->has($company, 'manager'))) {
            return null;
        }
        $active = $this->active($company, $row);
        if ($row['state'] === 'assigned' && !$active) {
            $row['state'] = 'expired';
        }
        return $row + [
            'can_act' => $canReview && $active && $row['delegate_id'] === $actor->userId,
            'can_revoke' => $canReview && $active && ($row['granted_by'] === $actor->userId || $actor->has($company, 'manager')),
        ];
    }

    public function candidates(int $company, array $excluded): array
    {
        $placeholders = implode(',', array_fill(0, count($excluded), '?'));
        $rows = $this->db->rows("SELECT id,display_name FROM users WHERE active = 1 AND id NOT IN ($placeholders) AND
            (is_admin = 1 OR EXISTS (SELECT 1 FROM memberships WHERE user_id = users.id AND company_id = ? AND role IN ('manager','approver')))
            ORDER BY display_name,id LIMIT 100", [...$excluded, $company]);
        return array_column($rows, 'display_name', 'id');
    }

    private function pending(int $company, string $kind, int $id): array
    {
        if ($kind === 'quote') {
            $document = $this->db->row('SELECT version,revision,state FROM quotes WHERE company_id = ? AND id = ? FOR UPDATE', [$company, $id]);
            $review = $document === null ? null : $this->db->row(
                'SELECT r.prepared_by,d.submitted_by FROM quote_revisions r JOIN quote_review d USING (company_id,quote_id,revision)
                 WHERE r.company_id = ? AND r.quote_id = ? AND r.revision = ? AND r.expires_on >= UTC_DATE() AND d.decision IS NULL',
                [$company, $id, $document['revision']],
            );
            $open = ($document['state'] ?? null) === 'pending' && $review !== null;
        } else {
            $document = $this->db->row('SELECT version,latest_revision AS revision,state FROM agreements WHERE company_id = ? AND id = ? FOR UPDATE', [$company, $id]);
            $review = $document === null ? null : $this->db->row(
                "SELECT prepared_by FROM agreement_versions WHERE company_id = ? AND agreement_id = ? AND revision = ? AND decision = 'pending'",
                [$company, $id, $document['revision']],
            );
            $open = ($document['state'] ?? null) !== 'ended' && $review !== null;
        }
        if ($document === null) {
            throw new BusinessError('未找到当前公司单据。', 404);
        }
        if (!$open) {
            throw new BusinessError('当前版本没有可委托的审批。', 409);
        }
        return $document + ['authors' => array_map('intval', array_values($review))];
    }

    private function assignment(int $company, string $kind, int $id, int $revision): ?array
    {
        return $this->db->row(
            'SELECT granted_by,delegate_id,delegation_version,expires_at,state,expires_at > UTC_TIMESTAMP(6) AS unexpired
             FROM review_delegations WHERE company_id = ? AND kind = ? AND object_id = ? AND revision = ?',
            [$company, $kind, $id, $revision],
        );
    }

    private function active(int $company, array $row, bool $lock = false): bool
    {
        return $row['state'] === 'assigned' && (bool) $row['unexpired']
            && $this->eligible($company, $row['granted_by'], $lock) && $this->eligible($company, $row['delegate_id'], $lock);
    }

    private function eligible(int $company, int $user, bool $lock = false): bool
    {
        if ($lock) {
            $this->db->row('SELECT id FROM users WHERE id = ? FOR SHARE', [$user]);
        }
        return $this->db->row("SELECT id FROM users WHERE id = ? AND active = 1 AND (is_admin = 1 OR EXISTS (
            SELECT 1 FROM memberships WHERE user_id = users.id AND company_id = ? AND role IN ('manager','approver')))", [$user, $company]) !== null;
    }

    private function setState(int $company, string $kind, int $id, int $revision, string $state): void
    {
        $this->db->run('UPDATE review_delegations SET state = ? WHERE company_id = ? AND kind = ? AND object_id = ? AND revision = ?',
            [$state, $company, $kind, $id, $revision]);
    }
}
