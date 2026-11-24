<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Outreach
{
    public const CHANNELS = ['email' => '邮箱', 'phone' => '电话'];
    public const BLOCKS = [
        'account_frozen' => '客户冻结', 'account_unavailable' => '客户状态不可用',
        'consent_missing' => '该渠道缺少客户成功触达授权', 'contact_changed' => '联系人已变更',
        'renewal_closed' => '续约周期已经结束或变更',
    ];
    public const ERRORS = ['gateway_configuration' => '渠道配置不完整', 'gateway_unconfirmed' => '渠道接收结果待确认'];
    private const PROCESSABLE = ['queued', 'blocked', 'retryable'];

    private readonly Audit $audit;
    private readonly Renewals $renewals;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
        $this->renewals = new Renewals($db);
    }

    public function queue(Access $actor, int $company, array $data): array
    {
        $channel = Input::choice($data['channel'], '触达渠道', array_keys(self::CHANNELS));
        $message = Input::text($data['message'], '触达内容', 1500);
        return $this->db->transaction(function () use ($actor, $company, $data, $channel, $message): array {
            [$renewal, $contract, $account] = $this->renewals->lock($actor, $company, $data['id']);
            Accounts::checkVersion($renewal, $data['version']);
            if (!$this->renewals->isCurrent($company, $renewal, $contract)) {
                throw new BusinessError('续约已结束或合同到期日已变化。', 409);
            }
            $contact = $this->contact($company, $account['id'], $data['contact_id'], $channel)
                ?? throw new BusinessError('未找到该客户的联系人。', 404);
            $mode = OutreachGateway::mode();
            $key = Input::digest([$renewal['id'], $contact['id'], $contact['version'], $channel, $mode, $message]);
            $existing = $this->db->row('SELECT * FROM outreach_jobs WHERE company_id = ? AND idempotency_key = ?', [$company, $key]);
            if ($existing !== null) {
                return $this->result($existing, reused: true);
            }
            $block = $this->block($account, $contact, $contact['version'], $channel);
            $state = $block === null ? 'queued' : 'blocked';
            $id = $this->db->insert(
                'INSERT INTO outreach_jobs (company_id,renewal_id,contact_id,contact_version,channel,gateway_mode,message,state,block_reason,
                    idempotency_key,requested_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$company, $renewal['id'], $contact['id'], $contact['version'], $channel, $mode, $message, $state, $block, $key, $actor->userId],
            );
            $job = $this->row($company, $id);
            $this->audit->record($actor, $company, 'renewal', $renewal['id'], $renewal['version'], 'outreach.queue',
                ['outreach_id' => $id, 'contact_id' => $contact['id'], 'channel' => $channel, 'state' => $state, 'block_reason' => $block]);
            return $this->result($job);
        });
    }

    public function process(Access $actor, int $company, array $data): array
    {
        return $this->db->transaction(function () use ($actor, $company, $data): array {
            [$job, $renewal, $contract, $account] = $this->lock($actor, $company, $data['id']);
            Accounts::checkVersion($job, $data['version']);
            $this->assertProcessable($job);
            $contact = $this->contact($company, $account['id'], $job['contact_id'], $job['channel']);
            $block = $this->renewals->isCurrent($company, $renewal, $contract)
                ? $this->block($account, $contact, $job['contact_version'], $job['channel'])
                : 'renewal_closed';
            if ($block !== null && $job['state'] === 'blocked' && $block === $job['block_reason']) {
                return $this->result($job);
            }
            $delivery = $block === null
                ? OutreachGateway::submit($job['gateway_mode'], $job['idempotency_key'], $job['channel'], $contact[$job['channel']], $job['message'])
                : ['state' => 'blocked'];
            $retryAfter = $delivery['state'] !== 'retryable' ? null
                : gmdate('Y-m-d H:i:s', time() + 60 * (2 ** min($job['attempts'], 8)));
            $this->db->run(
                'UPDATE outreach_jobs SET state = ?,block_reason = ?,last_error = ?,provider_reference = ?,processed_by = ?,
                 retry_after = ?,attempts = attempts + ?,version = version + 1 WHERE company_id = ? AND id = ?',
                [$delivery['state'], $block, $delivery['error'] ?? ($block === null ? null : $job['last_error']),
                    $delivery['reference'] ?? null, $actor->userId, $retryAfter,
                    $block === null ? 1 : 0, $company, $job['id']],
            );
            $result = $this->result($this->row($company, $job['id']));
            $this->audit->record($actor, $company, 'renewal', $renewal['id'], $renewal['version'], 'outreach.process', $result);
            return $result;
        });
    }

    public function cancel(Access $actor, int $company, array $data): array
    {
        $reason = Input::text($data['reason'], '取消原因', 240);
        return $this->db->transaction(function () use ($actor, $company, $data, $reason): array {
            [$job, $renewal] = $this->lock($actor, $company, $data['id']);
            Accounts::checkVersion($job, $data['version']);
            $this->assertProcessable($job);
            $this->db->run(
                "UPDATE outreach_jobs SET state = 'cancelled',block_reason = NULL,retry_after = NULL,processed_by = ?,
                 version = version + 1 WHERE company_id = ? AND id = ?", [$actor->userId, $company, $job['id']],
            );
            $result = $this->result($this->row($company, $job['id']));
            $this->audit->record($actor, $company, 'renewal', $renewal['id'], $renewal['version'], 'outreach.cancel', $result + ['reason' => $reason]);
            return $result;
        });
    }

    public function related(Access $actor, int $company, int $renewalId, int $after): array
    {
        $this->renewals->readable($actor, $company, $renewalId);
        return $this->db->rows(
            'SELECT id,renewal_id,contact_id,contact_version,channel,gateway_mode,message,state,block_reason,last_error,
                provider_reference,requested_by,processed_by,attempts,retry_after,version,created_at,updated_at FROM outreach_jobs
             WHERE company_id = ? AND renewal_id = ? AND id > ? ORDER BY id LIMIT 100', [$company, $renewalId, $after],
        );
    }

    public function processDue(Access $actor, int $company): array
    {
        $actor->allow($company, 'manager');
        $pending = $this->db->rows(
            "SELECT id,version FROM outreach_jobs WHERE company_id = ? AND
                (state = 'queued' OR (state = 'retryable' AND retry_after <= UTC_TIMESTAMP(6)))
             ORDER BY id LIMIT 20", [$company],
        );
        $ops = new Operations($this->db);
        $items = [];
        foreach ($pending as $job) {
            $key = 'outreach-due:' . $job['id'] . ':' . $job['version'];
            try {
                $items[] = $ops->execute($actor, $company, 'outreach.process', $key, $job)['result'];
            } catch (BusinessError $error) {
                if ($error->status !== 409) {
                    throw $error;
                }
                $items[] = ['id' => $job['id'], 'state' => 'changed', 'reason' => $error->getMessage()];
            }
        }
        return ['count' => count($items), 'items' => $items];
    }

    public function contacts(Access $actor, int $company, int $renewalId): array
    {
        $renewal = $this->renewals->readable($actor, $company, $renewalId);
        $agreement = (new Agreements($this->db))->subject($company, $renewal['agreement_id']);
        $account = (new Accounts($this->db))->currentId($company, $agreement['account_id']);
        return $this->db->rows(
            "SELECT c.id,c.name,c.job_title,(c.email IS NOT NULL AND COALESCE(e.allowed,0)) AS email_allowed,
                (c.phone IS NOT NULL AND COALESCE(p.allowed,0)) AS phone_allowed
             FROM contacts c LEFT JOIN contact_permissions e
                 ON e.company_id = c.company_id AND e.contact_id = c.id AND e.purpose = 'success' AND e.channel = 'email'
             LEFT JOIN contact_permissions p
                 ON p.company_id = c.company_id AND p.contact_id = c.id AND p.purpose = 'success' AND p.channel = 'phone'
             WHERE c.company_id = ? AND c.account_id = ? ORDER BY c.id LIMIT 100", [$company, $account],
        );
    }

    private function contact(int $company, int $account, int $id, string $channel): ?array
    {
        return $this->db->row(
            "SELECT c.id,c.version,c.email,c.phone,p.allowed FROM contacts c
             LEFT JOIN contact_permissions p ON p.company_id = c.company_id AND p.contact_id = c.id
                 AND p.purpose = 'success' AND p.channel = ?
             WHERE c.company_id = ? AND c.account_id = ? AND c.id = ?", [$channel, $company, $account, $id],
        );
    }

    private function block(array $account, ?array $contact, int $version, string $channel): ?string
    {
        return match (true) {
            $account['state'] === 'frozen' => 'account_frozen',
            $account['state'] !== 'active' => 'account_unavailable',
            $contact === null => 'contact_changed',
            !$contact['allowed'] || $contact[$channel] === null => 'consent_missing',
            $contact['version'] !== $version => 'contact_changed',
            default => null,
        };
    }

    private function lock(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'success');
        $job = $this->row($company, $id);
        [$renewal, $contract, $account] = $this->renewals->lock($actor, $company, $job['renewal_id']);
        return [$this->row($company, $id, true), $renewal, $contract, $account];
    }

    private function assertProcessable(array $job): void
    {
        if (!in_array($job['state'], self::PROCESSABLE, true)) {
            throw new BusinessError('当前触达记录已结束。', 409);
        }
    }

    private function result(array $job, bool $reused = false): array
    {
        return array_intersect_key($job, array_flip([
            'id', 'renewal_id', 'version', 'state', 'block_reason', 'attempts', 'last_error', 'provider_reference', 'retry_after',
        ])) + ['reused' => $reused];
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        return $this->db->row('SELECT * FROM outreach_jobs WHERE company_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到触达记录。', 404);
    }
}
