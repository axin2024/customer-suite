<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Audit
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(Access $actor, int $companyId, string $kind, int $id, int $version, string $action, array $details = []): void
    {
        $this->db->run(
            'INSERT INTO audit_events (company_id,actor_id,kind,object_id,version,action,details) VALUES (?,?,?,?,?,?,?)',
            [$companyId, $actor->userId, $kind, $id, $version, $action, Input::json($details)],
        );
    }

    public function timeline(int $companyId, string $kind, int $id, int $after = 0): array
    {
        $rows = $this->db->rows(
            'SELECT e.id,e.actor_id,u.display_name AS actor_name,e.version,e.action,e.details,e.occurred_at
             FROM audit_events e JOIN users u ON u.id = e.actor_id
             WHERE e.company_id = ? AND e.kind = ? AND e.object_id = ? AND e.id > ?
             ORDER BY e.id LIMIT 100',
            [$companyId, $kind, $id, $after],
        );
        foreach ($rows as &$row) {
            $row['details'] = json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $rows;
    }
}
