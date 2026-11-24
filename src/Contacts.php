<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Contacts
{
    private readonly Accounts $accounts;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->accounts = new Accounts($db);
        $this->audit = new Audit($db);
    }

    public function save(Access $actor, int $companyId, int $accountId, array $input, ?int $contactId = null): array
    {
        $name = Input::text($input['name'] ?? null, '联系人姓名', 120);
        $jobTitle = Input::text($input['job_title'] ?? null, '职位', 100);
        $email = Input::email($input['email'] ?? null);
        $phone = Input::phone($input['phone'] ?? null);
        return $this->db->transaction(function () use ($actor, $companyId, $accountId, $name, $jobTitle, $email, $phone, $contactId, $input): array {
            $this->accounts->writable($actor, $companyId, $accountId);
            if ($contactId === null) {
                $id = $this->db->insert(
                    'INSERT INTO contacts (company_id,account_id,original_account_id,name,job_title,email,phone) VALUES (?,?,?,?,?,?,?)',
                    [$companyId, $accountId, $accountId, $name, $jobTitle, $email, $phone],
                );
                $version = 1;
            } else {
                $id = $contactId;
                $current = $this->contact($companyId, $accountId, $id, true);
                $expected = Input::id($input['version'] ?? null, '联系人版本');
                Accounts::checkVersion($current, $expected);
                $version = $expected + 1;
                $this->db->run(
                    'UPDATE contacts SET name = ?,job_title = ?,email = ?,phone = ?,version = ?
                     WHERE company_id = ? AND account_id = ? AND id = ?',
                    [$name, $jobTitle, $email, $phone, $version, $companyId, $accountId, $id],
                );
                foreach (['email' => $email, 'phone' => $phone] as $channel => $value) {
                    if ($value !== $current[$channel]) {
                        $this->db->run(
                            "UPDATE contact_permissions SET allowed = 0,evidence = 'contact-channel-changed',changed_by = ?
                             WHERE company_id = ? AND contact_id = ? AND channel = ? AND allowed = 1",
                            [$actor->userId, $companyId, $id, $channel],
                        );
                        $this->audit->record($actor, $companyId, 'contact', $id, $version, 'contact.channel.change', ['channel' => $channel]);
                    }
                }
            }
            $this->audit->record($actor, $companyId, 'contact', $id, $version,
                $contactId === null ? 'contact.create' : 'contact.revise', ['account_id' => $accountId]);
            return ['id' => $id, 'version' => $version, 'account_id' => $accountId];
        });
    }

    public function forAccount(Access $actor, int $companyId, int $accountId, int $after = 0): array
    {
        $this->accounts->find($actor, $companyId, $accountId);
        $fields = $actor->has($companyId, 'manager', 'sales') ? ',email,phone' : '';
        return $this->db->rows(
            'SELECT id,name,job_title,version,original_account_id' . $fields .
            ' FROM contacts WHERE company_id = ? AND account_id = ? AND id > ? ORDER BY id LIMIT 100',
            [$companyId, $accountId, $after],
        );
    }

    public function permission(Access $actor, int $companyId, int $accountId, int $id, int $version, array $input): array
    {
        $purpose = Input::choice($input['purpose'] ?? null, '使用目的', ['export', 'marketing', 'success', 'service']);
        $channel = Input::choice($input['channel'] ?? null, '联系渠道', ['email', 'phone']);
        $allowed = ($input['allowed'] ?? null) === true;
        if (!is_bool($input['allowed'] ?? null)) {
            throw new BusinessError('请选择同意或撤回。');
        }
        $evidence = Input::text($input['evidence'] ?? null, '同意记录凭据编号', 160);
        return $this->db->transaction(function () use ($actor, $companyId, $accountId, $id, $version, $purpose, $channel, $allowed, $evidence): array {
            $this->accounts->writable($actor, $companyId, $accountId);
            $contact = $this->contact($companyId, $accountId, $id, true);
            Accounts::checkVersion($contact, $version);
            if ($allowed && $contact[$channel] === null) {
                throw new BusinessError('联系人缺少该渠道的联系方式。');
            }
            $this->db->run(
                'INSERT INTO contact_permissions (company_id,contact_id,purpose,channel,allowed,evidence,changed_by)
                 VALUES (?,?,?,?,?,?,?) AS decision
                 ON DUPLICATE KEY UPDATE allowed = decision.allowed,evidence = decision.evidence,changed_by = decision.changed_by,
                 changed_at = UTC_TIMESTAMP(6)',
                [$companyId, $id, $purpose, $channel, (int) $allowed, $evidence, $actor->userId],
            );
            $this->db->run('UPDATE contacts SET version = version + 1 WHERE company_id = ? AND id = ?', [$companyId, $id]);
            $this->audit->record($actor, $companyId, 'contact', $id, $version + 1, 'contact.permission', [
                'purpose' => $purpose, 'channel' => $channel, 'allowed' => $allowed, 'evidence' => $evidence,
            ]);
            return ['id' => $id, 'version' => $version + 1, 'allowed' => $allowed];
        });
    }

    public function directory(Access $actor, int $companyId, int $accountId, string $channel): array
    {
        $this->accounts->find($actor, $companyId, $accountId);
        $actor->allow($companyId, 'manager', 'sales');
        $channel = Input::choice($channel, '渠道', ['email', 'phone']);
        $manager = $actor->has($companyId, 'manager');
        return $this->db->rows(
            'SELECT c.id,c.name,c.job_title,c.' . $channel . ' AS address FROM contacts c
             JOIN contact_permissions p ON p.company_id = c.company_id AND p.contact_id = c.id
             JOIN accounts a ON a.company_id = c.company_id AND a.id = c.account_id
             WHERE c.company_id = ? AND c.account_id = ? AND a.state = \'active\'
             AND p.purpose = \'export\' AND p.channel = ? AND p.allowed = 1
             AND c.' . $channel . ' IS NOT NULL' . ($manager ? '' : ' AND a.owner_id = ?') . ' ORDER BY c.id',
            [$companyId, $accountId, $channel, ...($manager ? [] : [$actor->userId])],
        );
    }

    private function contact(int $companyId, int $accountId, int $id, bool $lock): array
    {
        return $this->db->row(
            'SELECT * FROM contacts WHERE company_id = ? AND account_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$companyId, $accountId, $id],
        ) ?? throw new BusinessError('未找到联系人。', 404);
    }
}
