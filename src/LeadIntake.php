<?php

declare(strict_types=1);

namespace CustomerSuite;

final class LeadIntake
{
    public function __construct(private readonly Database $db)
    {
    }

    public function receive(Access $actor, int $companyId, array $input): array
    {
        $actor->allow($companyId, 'manager', 'marketing');
        $name = Input::text($input['name'] ?? null, '线索名称', 160);
        $email = Input::email($input['email'] ?? null);
        $phone = Input::phone($input['phone'] ?? null);
        $campaignId = Input::id($input['campaign_id'] ?? null, '活动编号');
        $source = strtolower(Input::key($input['source'] ?? null, '来源', 40));
        $externalId = Input::key($input['external_id'] ?? null, '来源记录编号', 120);
        $occurred = Input::instant($input['occurred_at'] ?? null);
        $identities = array_filter(['email' => $email, 'phone' => $phone], static fn (?string $value): bool => $value !== null);
        if ($identities === []) {
            throw new BusinessError('至少提供一个可核对的邮箱或电话。');
        }
        $digest = Input::digest([$campaignId, $name, $email, $phone, $occurred]);
        return $this->db->transaction(function () use ($actor, $companyId, $name, $email, $phone, $campaignId, $source, $externalId, $occurred, $identities, $digest): array {
            $this->db->run(
                'INSERT INTO lead_intake_locks (company_id) VALUES (?) ON DUPLICATE KEY UPDATE company_id = company_id',
                [$companyId],
            );
            $existing = $this->db->row(
                'SELECT id,lead_id,payload_digest FROM lead_touches WHERE company_id = ? AND source = ? AND external_id = ?',
                [$companyId, $source, $externalId],
            );
            if ($existing) {
                if (!hash_equals($existing['payload_digest'], $digest)) {
                    throw new BusinessError('来源记录编号已收录，提交内容与原回执不一致。', 409);
                }
                $lead = $this->lead($companyId, (int) $existing['lead_id']);
                return $this->result($lead, (int) $existing['id'], 'replay');
            }
            if (!$this->db->row('SELECT id FROM campaigns WHERE company_id = ? AND id = ?', [$companyId, $campaignId])) {
                throw new BusinessError('未找到本公司活动。', 404);
            }
            $candidates = $this->candidates($companyId, $identities);
            if (count($candidates) > 1) {
                throw new BusinessError('邮箱和电话分别指向不同线索，须核对身份。', 409, ['candidate_ids' => $candidates]);
            }
            $created = $candidates === [];
            if ($created) {
                $leadId = $this->db->insert(
                    'INSERT INTO leads (company_id,name,email,phone) VALUES (?,?,?,?)', [$companyId, $name, $email, $phone],
                );
            } else {
                $leadId = $candidates[0];
                $this->lead($companyId, $leadId, true);
                $this->db->run(
                    'UPDATE leads SET email = COALESCE(email,?),phone = COALESCE(phone,?),version = version + 1
                     WHERE company_id = ? AND id = ?',
                    [$email, $phone, $companyId, $leadId],
                );
            }
            foreach ($identities as $kind => $value) {
                $this->db->run(
                    'INSERT INTO lead_identity_keys (company_id,kind,key_digest,lead_id) VALUES (?,?,UNHEX(?),?)
                     ON DUPLICATE KEY UPDATE lead_id = lead_id',
                    [$companyId, $kind, hash('sha256', $value), $leadId],
                );
            }
            $touchId = $this->db->insert(
                'INSERT INTO lead_touches (company_id,lead_id,campaign_id,source,external_id,payload_digest,occurred_at,imported_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$companyId, $leadId, $campaignId, $source, $externalId, $digest, $occurred, $actor->userId],
            );
            $lead = $this->lead($companyId, $leadId);
            (new Audit($this->db))->record($actor, $companyId, 'lead', $leadId, (int) $lead['version'], 'lead.touch.receive',
                ['touch_id' => $touchId, 'campaign_id' => $campaignId, 'new_lead' => $created]);
            return $this->result($lead, $touchId, $created ? 'created' : 'matched');
        });
    }

    private function candidates(int $companyId, array $identities): array
    {
        $candidates = [];
        foreach ($identities as $kind => $value) {
            $match = $this->db->row(
                'SELECT lead_id FROM lead_identity_keys WHERE company_id = ? AND kind = ? AND key_digest = UNHEX(?)',
                [$companyId, $kind, hash('sha256', $value)],
            );
            if ($match !== null) {
                $candidates[] = (int) $match['lead_id'];
            }
        }
        return array_values(array_unique($candidates));
    }

    private function lead(int $companyId, int $leadId, bool $lock = false): array
    {
        return $this->db->row(
            'SELECT id,version,owner_id FROM leads WHERE company_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$companyId, $leadId],
        ) ?? throw new BusinessError('未找到线索。', 404);
    }

    private function result(array $lead, int $touchId, string $match): array
    {
        return ['id' => (int) $lead['id'], 'version' => (int) $lead['version'], 'touch_id' => $touchId,
            'owner_id' => $lead['owner_id'] === null ? null : (int) $lead['owner_id'], 'match' => $match];
    }
}
