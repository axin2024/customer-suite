<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Handovers
{
    public const STATES = ['planning', 'executing', 'accepted'];

    private readonly Audit $audit;
    private readonly Agreements $agreements;
    private readonly DeliveryMilestones $milestones;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
        $this->agreements = new Agreements($db);
        $this->milestones = new DeliveryMilestones($db);
    }

    public function open(Access $actor, int $company, array $data): array
    {
        return $this->db->transaction(function () use ($actor, $company, $data): array {
            $contract = $this->agreements->forSalesUpdate($actor, $company, $data['agreement_id']);
            Accounts::checkVersion($contract, $data['agreement_version']);
            if ($contract['active_revision'] !== $data['agreement_revision'] || $contract['state'] === 'ended') {
                throw new BusinessError('只可对当前已签合同版本发起交接。', 409);
            }
            $actor->eligible($company, $data['owner_id'], 'delivery');
            $contact = $this->customerContact($company, $contract['account_id'], $data['contact_id']);
            if ($this->db->row('SELECT id FROM handovers WHERE company_id = ? AND agreement_id = ? AND agreement_revision = ?',
                [$company, $contract['id'], $contract['active_revision']])) {
                throw new BusinessError('此合同版本已有交接工作单。', 409);
            }
            $id = $this->db->insert('INSERT INTO handovers (company_id,agreement_id,agreement_revision,owner_id) VALUES (?,?,?,?)',
                [$company, $contract['id'], $contract['active_revision'], $data['owner_id']]);
            $this->saveScope($actor, $company, $id, 1, $data['scope'], $contact);
            $this->audit->record($actor, $company, 'handover', $id, 1, 'handover.open',
                ['agreement_id' => $contract['id'], 'agreement_revision' => $contract['active_revision'], 'delivery_owner_id' => $data['owner_id']]);
            return ['id' => $id, 'version' => 1, 'state' => 'planning', 'agreement_revision' => $contract['active_revision']];
        });
    }

    public function revise(Access $actor, int $company, int $id, int $version, array $data): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $data): array {
            $actor->allow($company, 'sales', 'manager');
            $handover = $this->row($company, $id);
            $contract = $this->agreements->forSalesUpdate($actor, $company, $handover['agreement_id']);
            $handover = $this->row($company, $id, true);
            Accounts::checkVersion($handover, $version);
            if ($handover['state'] !== 'planning') {
                throw new BusinessError('开始履约后须保留已确认范围。', 409);
            }
            $contact = $this->customerContact($company, $contract['account_id'], $data['contact_id']);
            $revision = $handover['scope_revision'] + 1;
            $this->saveScope($actor, $company, $id, $revision, $data['scope'], $contact);
            $this->db->run('UPDATE handovers SET scope_revision = ? WHERE company_id = ? AND id = ?', [$revision, $company, $id]);
            return $this->record($actor, $company, $handover, 'handover.revise', ['scope_revision' => $revision]);
        });
    }

    public function confirm(Access $actor, int $company, int $id, int $version, string $side): array
    {
        $side = Input::choice($side, '确认岗位', ['sales', 'delivery']);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $side): array {
            if ($side === 'sales') {
                $actor->allow($company, 'sales', 'manager');
                $this->agreements->forSalesUpdate($actor, $company, $this->row($company, $id)['agreement_id']);
                $handover = $this->row($company, $id, true);
            } else {
                $handover = $this->deliveryLock($actor, $company, $id);
            }
            Accounts::checkVersion($handover, $version);
            $this->assertOpen($handover);
            $confirmations = $this->confirmations($handover);
            if (isset($confirmations[$side])) {
                throw new BusinessError('当前人员与范围版本已确认。', 409);
            }
            if (in_array($actor->userId, $confirmations, true)) {
                throw new BusinessError('销售和实施须由两名实际操作者分别确认。', 403);
            }
            $ownerRevision = $side === 'delivery' ? $handover['owner_revision'] : 0;
            $this->db->run(
                'INSERT INTO handover_confirmations (company_id,handover_id,scope_revision,side,owner_revision,actor_id) VALUES (?,?,?,?,?,?)',
                [$company, $id, $handover['scope_revision'], $side, $ownerRevision, $actor->userId],
            );
            if ($confirmations !== []) {
                $handover['state'] = 'executing';
                $this->db->run("UPDATE handovers SET state = 'executing' WHERE company_id = ? AND id = ?", [$company, $id]);
            }
            return $this->record($actor, $company, $handover, 'handover.confirm', ['side' => $side, 'scope_revision' => $handover['scope_revision']]);
        });
    }

    public function assign(Access $actor, int $company, int $id, int $version, int $owner, string $reason): array
    {
        $actor->allow($company, 'manager');
        $reason = Input::text($reason, '改派依据', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $owner, $reason): array {
            $handover = $this->row($company, $id, true);
            Accounts::checkVersion($handover, $version);
            $this->assertOpen($handover);
            $actor->eligible($company, $owner, 'delivery');
            if ($owner === $handover['owner_id']) {
                throw new BusinessError('请选择不同的交付负责人。');
            }
            $this->db->run('UPDATE handovers SET owner_id = ?,owner_revision = owner_revision + 1 WHERE company_id = ? AND id = ?', [$owner, $company, $id]);
            return $this->record($actor, $company, $handover, 'handover.assign', ['old_owner_id' => $handover['owner_id'], 'owner_id' => $owner, 'reason' => $reason]);
        });
    }

    public function updateDelivery(Access $actor, int $company, int $id, int $version, string $action, array $data): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $action, $data): array {
            $handover = $this->deliveryLock($actor, $company, $id);
            Accounts::checkVersion($handover, $version);
            $this->assertOpen($handover);
            if ($handover['state'] !== 'executing' || !isset($this->confirmations($handover)['delivery'])) {
                throw new BusinessError('当前交付人员须与销售确认范围，才能办理里程碑和待办。', 409);
            }
            $details = $this->milestones->mutate($actor, $company, $id, $action, $data);
            return $this->record($actor, $company, $handover, $action, $details);
        });
    }

    public function accept(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $evidence = Evidence::parse($data['file_name'], $data['file_base64']);
        $confirmedAt = Input::instant($data['confirmed_at']);
        $reference = Input::text($data['evidence_reference'], '客户确认编号', 160);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $data, $evidence, $confirmedAt, $reference): array {
            $handover = $this->deliveryLock($actor, $company, $id);
            Accounts::checkVersion($handover, $version);
            if ($handover['state'] !== 'executing' || count($this->confirmations($handover)) !== 2) {
                throw new BusinessError('验收前须确认交付范围和当前负责人。', 409);
            }
            if ($data['agreement_revision'] !== $handover['agreement_revision']) {
                throw new BusinessError('验收回执版本与本交接所绑定的合同版本不一致。', 409);
            }
            $scope = $this->scope($handover);
            if ($data['contact_id'] !== $scope['contact_id']) {
                throw new BusinessError('验收联系人须为此交接版本中确认的客户代表。');
            }
            $agreement = $this->agreements->revision($company, $handover['agreement_id'], $handover['agreement_revision']);
            if ($confirmedAt < $agreement['decided_at'] || $confirmedAt > $this->db->row('SELECT UTC_TIMESTAMP(6) + INTERVAL 5 MINUTE AS latest')['latest']) {
                throw new BusinessError('确认时间须在合同版本签署后，且不得超前当前时间。');
            }
            $this->milestones->assertReady($company, $id);
            $this->db->run(
                'INSERT INTO delivery_acceptances (company_id,handover_id,agreement_id,agreement_revision,scope_revision,contact_id,
                    evidence_reference,confirmation_at,recorded_by,file_name,media_type,byte_length,sha256,body) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$company, $id, $handover['agreement_id'], $handover['agreement_revision'], $handover['scope_revision'], $scope['contact_id'],
                    $reference, $confirmedAt, $actor->userId, ...array_values($evidence)],
            );
            $handover['state'] = 'accepted';
            $this->db->run("UPDATE handovers SET state = 'accepted' WHERE company_id = ? AND id = ?", [$company, $id]);
            return $this->record($actor, $company, $handover, 'handover.accept',
                ['agreement_revision' => $handover['agreement_revision'], 'scope_revision' => $handover['scope_revision'], 'sha256' => $evidence['sha256']]);
        });
    }

    public function list(Access $actor, int $company, string $state, ?string $cursor): array
    {
        $actor->allow($company, 'manager', 'sales', 'delivery');
        Input::choice($state, '交接状态', self::STATES);
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT h.id,h.agreement_id,h.agreement_revision,h.owner_id,h.state,h.version,h.updated_at,a.reference_no,a.legal_name
             FROM handovers h JOIN agreements a ON a.company_id = h.company_id AND a.id = h.agreement_id
             JOIN accounts c ON c.company_id = a.company_id AND c.id = a.account_id
             WHERE h.company_id = ? AND h.state = ? AND
                (? = 1 OR (? = 1 AND h.owner_id = ?) OR (? = 1 AND a.owner_id = ? AND c.owner_id = ?))'
             . $page->clause('h') . ' ORDER BY h.updated_at DESC,h.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, $state, (int) $actor->has($company, 'manager'), (int) $actor->has($company, 'delivery'), $actor->userId,
                (int) $actor->has($company, 'sales'), $actor->userId, $actor->userId, ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id, int $after = 0): array
    {
        $handover = $this->readable($actor, $company, $id);
        $handover['scope'] = $this->scope($handover);
        $handover['confirmations'] = $this->confirmations($handover);
        $handover['timeline'] = $this->audit->timeline($company, 'handover', $id, $after);
        $handover['acceptance'] = $this->db->row(
            'SELECT agreement_id,agreement_revision,scope_revision,contact_id,evidence_reference,confirmation_at,recorded_by,recorded_at,
                file_name,media_type,byte_length,sha256 FROM delivery_acceptances WHERE company_id = ? AND handover_id = ?', [$company, $id],
        );
        return $handover + $this->milestones->details($company, $id, $after);
    }

    public function evidence(Access $actor, int $company, int $id): array
    {
        $this->readable($actor, $company, $id);
        $file = $this->db->row('SELECT file_name,media_type,byte_length,sha256,body FROM delivery_acceptances WHERE company_id = ? AND handover_id = ?', [$company, $id])
            ?? throw new BusinessError('未找到该交接的验收附件。', 404);
        if (strlen($file['body']) !== $file['byte_length'] || !hash_equals($file['sha256'], hash('sha256', $file['body']))) {
            throw new BusinessError('附件暂不可读取，请联系管理员核验摘要。', 503);
        }
        return $file;
    }

    public function readable(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'sales', 'delivery');
        $handover = $this->row($company, $id);
        $manager = $actor->has($company, 'manager');
        $delivery = $actor->has($company, 'delivery') && $actor->userId === $handover['owner_id'];
        $sales = $actor->has($company, 'sales') && $actor->userId === $handover['sales_owner_id'] && $actor->userId === $handover['account_owner_id'];
        if (!$manager && !$delivery && !$sales) {
            throw new BusinessError('未找到交接工作单。', 404);
        }
        unset($handover['account_owner_id']);
        return $handover + ['can_assign' => $manager, 'can_sales' => $manager || $sales, 'can_deliver' => $delivery];
    }

    private function deliveryLock(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'delivery');
        $handover = $this->row($company, $id, true);
        if ($actor->userId !== (int) $handover['owner_id']) {
            throw new BusinessError('只有当前交付负责人可以办理。', 403);
        }
        return $handover;
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        $sql = $lock ? 'SELECT h.* FROM handovers h' : 'SELECT h.*,a.reference_no,a.legal_name,a.owner_id AS sales_owner_id,c.owner_id AS account_owner_id
            FROM handovers h JOIN agreements a ON a.company_id = h.company_id AND a.id = h.agreement_id
            JOIN accounts c ON c.company_id = a.company_id AND c.id = a.account_id';
        return $this->db->row($sql . ' WHERE h.company_id = ? AND h.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到交接工作单。', 404);
    }

    private function customerContact(int $company, int $account, int $contact): array
    {
        $current = (new Accounts($this->db))->currentId($company, $account);
        return $this->db->row('SELECT id,name,job_title,version FROM contacts WHERE company_id = ? AND account_id = ? AND id = ? FOR SHARE',
            [$company, $current, $contact]) ?? throw new BusinessError('交接联系人须属于该签约客户。');
    }

    private function saveScope(Access $actor, int $company, int $id, int $revision, string $summary, array $contact): void
    {
        $summary = Input::text($summary, '交付范围', 2000);
        $this->db->run('INSERT INTO handover_scopes (company_id,handover_id,revision,scope_summary,contact_id,contact_name,contact_version,contact_title,changed_by)
            VALUES (?,?,?,?,?,?,?,?,?)', [$company, $id, $revision, $summary, $contact['id'], $contact['name'], $contact['version'], $contact['job_title'], $actor->userId]);
    }

    private function scope(array $handover): array
    {
        return $this->db->row('SELECT revision,scope_summary,contact_id,contact_name,contact_version,contact_title,created_at
            FROM handover_scopes WHERE company_id = ? AND handover_id = ? AND revision = ?',
            [$handover['company_id'], $handover['id'], $handover['scope_revision']]);
    }

    private function confirmations(array $handover): array
    {
        return array_column($this->db->rows(
            "SELECT side,actor_id FROM handover_confirmations WHERE company_id = ? AND handover_id = ? AND scope_revision = ?
                AND (side = 'sales' OR owner_revision = ?)",
            [$handover['company_id'], $handover['id'], $handover['scope_revision'], $handover['owner_revision']],
        ), 'actor_id', 'side');
    }

    private function assertOpen(array $handover): void
    {
        if ($handover['state'] === 'accepted') {
            throw new BusinessError('已验收工作单只可归档查阅。', 409);
        }
    }

    private function record(Access $actor, int $company, array $handover, string $action, array $details): array
    {
        $version = $handover['version'] + 1;
        $id = $handover['id'];
        $this->db->run('UPDATE handovers SET version = ? WHERE company_id = ? AND id = ?', [$version, $company, $id]);
        $this->audit->record($actor, $company, 'handover', $id, $version, $action, $details);
        return ['id' => $id, 'version' => $version, 'state' => $handover['state']] + $details;
    }
}
