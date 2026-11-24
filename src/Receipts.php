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
                return ['replayed' => true, 'result' => json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR)];
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
        return ['action' => $row['action'], 'created_at' => $row['created_at'],
            'result' => json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR)];
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
