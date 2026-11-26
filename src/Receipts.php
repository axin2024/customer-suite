<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Receipts
{
    public function __construct(private readonly Database $db)
    {
    }

    public function run(Access $actor, int $companyId, string $key, string $action, array $payload, callable $write): array
    {
        $key = Input::key($key, '请求标识', 128);
        $keyParts = [$companyId, $actor->userId, $key];
        $digest = Input::digest($payload);
        return $this->db->transaction(function () use ($actor, $companyId, $keyParts, $action, $digest, $write): array {
            $this->db->row('SELECT id FROM users WHERE id = ? FOR SHARE', [$actor->userId]);
            $actor->company($companyId);
            $this->db->run(
                'INSERT INTO operation_receipts (company_id,actor_id,request_key,action,payload_digest) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE payload_digest = payload_digest',
                [...$keyParts, $action, $digest],
            );
            $row = $this->lookUp($keyParts);
            if ($row['action'] !== $action || !hash_equals($row['payload_digest'], $digest)) {
                throw new BusinessError('请求标识已用于不同内容。', 409);
            }
            if ($row['response'] !== null) {
                $result = json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertReadable($actor, $companyId, $action, $result);
                return ['replayed' => true, 'result' => $result];
            }
            $result = $write();
            $this->db->run(
                'UPDATE operation_receipts SET response = ? WHERE company_id = ? AND actor_id = ? AND request_key = ?',
                [Input::json($result), ...$keyParts],
            );
            return ['replayed' => false, 'result' => $result];
        });
    }

    public function read(Access $actor, int $companyId, string $key): array
    {
        $actor->company($companyId);
        $row = $this->lookUp([$companyId, $actor->userId, Input::key($key, '请求标识', 128)]);
        if ($row === null || $row['response'] === null) {
            throw new BusinessError('未找到已完成的请求回执。', 404);
        }
        $result = json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertReadable($actor, $companyId, $row['action'], $result);
        return ['action' => $row['action'], 'created_at' => $row['created_at'], 'result' => $result];
    }

    private function assertReadable(Access $actor, int $company, string $action, array $result): void
    {
        if ($action === 'ticket.sweep' || str_starts_with($action, 'service.')) {
            $actor->allow($company, 'manager');
        } elseif (str_starts_with($action, 'ticket.')) {
            (new ServiceTickets($this->db))->readable($actor, $company, (int) $result['id']);
        } elseif (str_starts_with($action, 'handover.')) {
            (new Handovers($this->db))->readable($actor, $company, (int) $result['id']);
        } elseif (in_array($action, ['renewal.scan', 'success.assign'], true)) {
            $actor->allow($company, 'manager');
        } elseif ($action === 'success.health' || str_starts_with($action, 'renewal.')) {
            (new SuccessContracts($this->db))->readable($actor, $company, (int) $result['agreement_id']);
        } elseif (str_starts_with($action, 'outreach.')) {
            (new Renewals($this->db))->readable($actor, $company, (int) $result['renewal_id']);
        } elseif ($action === 'report.capture') {
            (new Reports($this->db))->assertReceipt($actor, $company, (int) $result['id']);
        } elseif ($action === 'report.fx') {
            $actor->allow($company, 'manager');
        } elseif (str_starts_with($action, 'review.') || in_array($action, ['quote.decide', 'agreement.decide'], true)) {
            $actor->allow($company, 'manager', 'approver');
        } elseif (str_starts_with($action, 'batch.')) {
            (new BatchJobs($this->db))->readable($actor, $company, (int) $result['id']);
        }
    }

    private function lookUp(array $keyParts): ?array
    {
        return $this->db->row(
            'SELECT action,payload_digest,response,created_at FROM operation_receipts
             WHERE company_id = ? AND actor_id = ? AND request_key = ?',
            $keyParts,
        );
    }
}
