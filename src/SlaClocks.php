<?php

declare(strict_types=1);

namespace CustomerSuite;

final class SlaClocks
{
    public function __construct(private readonly Database $db)
    {
    }

    public function start(int $company, int $ticket, string $priority, array $policy, string $at): void
    {
        $calendar = BusinessCalendar::fromRecord($policy);
        $limits = json_decode($policy['priority_limits'], true, 8, JSON_THROW_ON_ERROR)[$priority];
        foreach ($limits as $kind => $minutes) {
            $target = $minutes * BusinessCalendar::MINUTE;
            $this->db->run('INSERT INTO sla_clocks (company_id,ticket_id,kind,target_micros,running_since,deadline_at) VALUES (?,?,?,?,?,?)',
                [$company, $ticket, $kind, $target, $at, $calendar->deadline($at, $target)]);
        }
    }

    public function change(array $ticket, array $policy, string $action, string $at): void
    {
        if (in_array($action, ['wait', 'resume'], true) && !(bool) $policy['pause_for_customer']) {
            return;
        }
        $calendar = BusinessCalendar::fromRecord($policy);
        foreach ($this->rows($ticket['company_id'], $ticket['id']) as $clock) {
            if ($clock['stopped_at'] !== null || ($action === 'respond' && $clock['kind'] !== 'response')) {
                continue;
            }
            $elapsed = $this->elapsed($clock, $calendar, $at);
            $since = $action === 'resume' ? $at : null;
            $deadline = $since === null ? null : $calendar->deadline($since, $clock['target_micros'] - $elapsed);
            $stopped = in_array($action, ['respond', 'resolve'], true) ? $at : null;
            $this->db->run('UPDATE sla_clocks SET elapsed_micros = ?,running_since = ?,deadline_at = ?,stopped_at = ?
                WHERE company_id = ? AND ticket_id = ? AND kind = ?',
                [$elapsed, $since, $deadline, $stopped, $ticket['company_id'], $ticket['id'], $clock['kind']]);
        }
    }

    public function overdue(int $company, int $ticket, string $at): ?array
    {
        return $this->db->row('SELECT kind,deadline_at FROM sla_clocks WHERE company_id = ? AND ticket_id = ? AND deadline_at <= ?
            ORDER BY deadline_at,kind LIMIT 1', [$company, $ticket, $at]);
    }

    public function summary(int $company, int $ticket, array $policy, string $at): array
    {
        $calendar = BusinessCalendar::fromRecord($policy);
        $result = [];
        foreach ($this->rows($company, $ticket) as $clock) {
            $elapsed = $this->elapsed($clock, $calendar, $at);
            $result[$clock['kind']] = [
                'target_minutes' => intdiv($clock['target_micros'], BusinessCalendar::MINUTE),
                'elapsed_minutes' => BusinessCalendar::minutes($elapsed),
                'remaining_minutes' => BusinessCalendar::minutes($clock['target_micros'] - $elapsed),
                'state' => match (true) {
                    $clock['stopped_at'] !== null => 'complete',
                    $clock['running_since'] === null => 'paused',
                    default => 'running',
                },
                'deadline_at' => $clock['deadline_at'], 'stopped_at' => $clock['stopped_at'],
            ];
        }
        return $result;
    }

    private function rows(int $company, int $ticket): array
    {
        return $this->db->rows('SELECT kind,target_micros,elapsed_micros,running_since,deadline_at,stopped_at
            FROM sla_clocks WHERE company_id = ? AND ticket_id = ? ORDER BY kind', [$company, $ticket]);
    }

    private function elapsed(array $clock, BusinessCalendar $calendar, string $at): int
    {
        if ($clock['running_since'] === null || $at <= $clock['running_since']) {
            return $clock['elapsed_micros'];
        }
        if ($at >= $clock['deadline_at']) {
            return $clock['target_micros'];
        }
        return min($clock['target_micros'], $clock['elapsed_micros'] + $calendar->elapsed($clock['running_since'], $at));
    }
}
