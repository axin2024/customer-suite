<?php

declare(strict_types=1);

namespace CustomerSuite;

final class SuccessContracts
{
    public function __construct(private readonly Database $db)
    {
    }

    public function assign(Access $actor, int $company, array $data): array
    {
        $actor->allow($company, 'manager');
        $agreementId = $data['agreement_id'];
        $ownerId = $data['owner_id'];
        $reason = Input::text($data['reason'], '改派原因', 400);
        return $this->db->transaction(function () use ($actor, $company, $agreementId, $ownerId, $reason, $data): array {
            [$contract, , $previous] = $this->lock($actor, $company, $agreementId, requireAssigned: false);
            Accounts::checkVersion($contract, $data['agreement_version']);
            $assignment = $this->assignment($company, $agreementId);
            $version = $assignment['version'] ?? 0;
            Accounts::checkVersion(['version' => $version], $data['assignment_version'] ?? 0);
            $actor->eligible($company, $ownerId, 'success');
            if ((int) $previous === $ownerId) {
                return ['agreement_id' => $agreementId, 'owner_id' => $ownerId, 'version' => $version, 'changed' => false];
            }
            $this->db->run(
                'INSERT INTO success_assignments (company_id,agreement_id,owner_id,changed_by) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE owner_id = ?,changed_by = ?,version = version + 1',
                [$company, $agreementId, $ownerId, $actor->userId, $ownerId, $actor->userId],
            );
            $audit = new Audit($this->db);
            $details = ['previous_owner_id' => $previous, 'owner_id' => $ownerId, 'reason' => $reason];
            $audit->record($actor, $company, 'agreement', $agreementId, $contract['version'], 'success.assign', $details);
            $open = $this->db->rows(
                "SELECT id,version FROM renewals WHERE company_id = ? AND agreement_id = ? AND state IN ('scheduled','working')
                 ORDER BY id FOR UPDATE", [$company, $agreementId],
            );
            foreach ($open as $renewal) {
                $this->db->run(
                    'UPDATE renewals SET owner_id = ?,version = version + 1 WHERE company_id = ? AND id = ?',
                    [$ownerId, $company, $renewal['id']],
                );
                $audit->record($actor, $company, 'renewal', $renewal['id'], $renewal['version'] + 1, 'success.assign', $details);
            }
            return ['agreement_id' => $agreementId, 'owner_id' => $ownerId, 'version' => $version + 1, 'changed' => true];
        });
    }

    public function readable(Access $actor, int $company, int $agreementId): void
    {
        $actor->allow($company, 'manager', 'success');
        if (!$actor->has($company, 'manager') && $this->owner($company, $agreementId) !== $actor->userId) {
            throw new BusinessError('未找到分配给当前岗位的合同。', 404);
        }
    }

    public function lock(Access $actor, int $company, int $agreementId, bool $requireAssigned = true): array
    {
        $this->readable($actor, $company, $agreementId);
        $agreements = new Agreements($this->db);
        $original = $agreements->subject($company, $agreementId);
        $account = (new Accounts($this->db))->lockCurrent($company, $original['account_id']);
        $contract = $agreements->subject($company, $agreementId, true);
        $owner = $this->owner($company, $agreementId);
        if (!$actor->has($company, 'manager') && $owner !== $actor->userId) {
            throw new BusinessError('未找到分配给当前岗位的合同。', 404);
        }
        if ($requireAssigned && $owner === null) {
            throw new BusinessError('请先为合同分配客户成功负责人。', 409);
        }
        return [$contract, $account, $owner];
    }

    public function owner(int $company, int $agreementId): ?int
    {
        $row = $this->assignment($company, $agreementId);
        return $row === null ? null : (int) $row['owner_id'];
    }

    private function assignment(int $company, int $id): ?array
    {
        return $this->db->row('SELECT owner_id,version FROM success_assignments WHERE company_id = ? AND agreement_id = ?', [$company, $id]);
    }

    public function list(Access $actor, int $company, ?string $cursor): array
    {
        $actor->allow($company, 'manager', 'success');
        $manager = $actor->has($company, 'manager');
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT a.id,a.reference_no,a.legal_name,a.account_id,a.state,a.version,a.updated_at,v.ends_on,
                s.owner_id,u.display_name AS owner_name
             FROM agreements a JOIN agreement_versions v
                 ON v.company_id = a.company_id AND v.agreement_id = a.id AND v.revision = a.active_revision
             LEFT JOIN success_assignments s ON s.company_id = a.company_id AND s.agreement_id = a.id
             LEFT JOIN users u ON u.id = s.owner_id
             WHERE a.company_id = ?' . ($manager ? '' : ' AND s.owner_id = ?') . $page->clause('a') .
             ' ORDER BY a.updated_at DESC,a.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, ...($manager ? [] : [$actor->userId]), ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id): array
    {
        $this->readable($actor, $company, $id);
        $agreements = new Agreements($this->db);
        $contract = array_intersect_key($agreements->subject($company, $id), array_flip([
            'id', 'account_id', 'reference_no', 'legal_name', 'state', 'version', 'active_revision',
        ]));
        $active = $agreements->revision($company, $id, $contract['active_revision']);
        $contract['ends_on'] = $active['ends_on'];
        $assignment = $this->assignment($company, $id);
        $contract['owner_id'] = $assignment['owner_id'] ?? null;
        $contract['assignment_version'] = $assignment['version'] ?? 0;
        $main = (new Accounts($this->db))->currentId($company, $contract['account_id']);
        $contract['account_state'] = $this->db->row('SELECT state FROM accounts WHERE company_id = ? AND id = ?', [$company, $main])['state'];
        $contract['health'] = (new HealthSnapshots($this->db))->list($actor, $company, null, null, $id)['items'];
        $contract['renewals'] = (new Renewals($this->db))->list($actor, $company, null, null, $id)['items'];
        return $contract;
    }
}
