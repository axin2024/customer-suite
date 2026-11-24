<?php

declare(strict_types=1);

namespace CustomerSuite;

final class DeliveryMilestones
{
    public function __construct(private readonly Database $db)
    {
    }

    public function mutate(Access $actor, int $company, int $handover, string $action, array $data): array
    {
        return match ($action) {
            'handover.milestone.add' => $this->schedule($company, $handover, $data),
            'handover.milestone.delay' => $this->delay($actor, $company, $handover, $data),
            'handover.milestone.complete' => $this->complete($company, $handover, $data['milestone_id']),
            'handover.issue.add' => ['issue_id' => $this->openIssue($actor, $company, $handover, $data['title'])],
            'handover.issue.resolve' => $this->resolve($actor, $company, $handover, $data['issue_id'], $data['resolution']),
        };
    }

    public function details(int $company, int $handover, int $after): array
    {
        $keys = [$company, $handover, $after];
        return [
            'milestones' => $this->db->rows('SELECT id,title,due_on,state,completed_at FROM delivery_milestones
                WHERE company_id = ? AND handover_id = ? AND id > ? ORDER BY id LIMIT 100', $keys),
            'delays' => $this->db->rows('SELECT id,milestone_id,old_due_on,new_due_on,impact,actor_id,created_at FROM delivery_delays
                WHERE company_id = ? AND handover_id = ? AND id > ? ORDER BY id LIMIT 100', $keys),
            'issues' => $this->db->rows('SELECT id,delay_id,title,resolution,resolved_at FROM delivery_issues
                WHERE company_id = ? AND handover_id = ? AND id > ? ORDER BY id LIMIT 100', $keys),
        ];
    }

    public function assertReady(int $company, int $handover): void
    {
        $counts = $this->db->row("SELECT COUNT(*) AS total,COUNT(IF(state <> 'completed',1,NULL)) AS open FROM delivery_milestones
            WHERE company_id = ? AND handover_id = ?", [$company, $handover]);
        if (!$counts['total'] || $counts['open'] || $this->db->row(
            'SELECT id FROM delivery_issues WHERE company_id = ? AND handover_id = ? AND resolved_at IS NULL LIMIT 1', [$company, $handover],
        )) {
            throw new BusinessError('至少完成一个里程碑并关闭全部里程碑和待确认事项后，才可登记验收。', 409);
        }
    }

    private function schedule(int $company, int $handover, array $data): array
    {
        $title = Input::text($data['title'], '里程碑名称', 160);
        $due = Input::date($data['due_on']);
        $id = $this->db->insert('INSERT INTO delivery_milestones (company_id,handover_id,title,due_on) VALUES (?,?,?,?)',
            [$company, $handover, $title, $due]);
        return ['milestone_id' => $id, 'due_on' => $due];
    }

    private function delay(Access $actor, int $company, int $handover, array $data): array
    {
        $milestone = $this->milestone($company, $handover, $data['milestone_id']);
        $due = Input::date($data['due_on']);
        $impact = Input::text($data['impact'], '延期影响', 800);
        $pending = Input::text($data['pending_item'], '待确认事项', 240);
        if ($due <= $milestone['due_on']) {
            throw new BusinessError('延期日期须晚于当前日期。');
        }
        $id = $this->db->insert('INSERT INTO delivery_delays (company_id,handover_id,milestone_id,old_due_on,new_due_on,impact,actor_id)
            VALUES (?,?,?,?,?,?,?)', [$company, $handover, $milestone['id'], $milestone['due_on'], $due, $impact, $actor->userId]);
        $this->db->run("UPDATE delivery_milestones SET state = 'delayed',due_on = ? WHERE company_id = ? AND handover_id = ? AND id = ?",
            [$due, $company, $handover, $milestone['id']]);
        return ['milestone_id' => $milestone['id'], 'delay_id' => $id, 'issue_id' => $this->openIssue($actor, $company, $handover, $pending, $id)];
    }

    private function complete(int $company, int $handover, int $id): array
    {
        $this->milestone($company, $handover, $id);
        $this->db->run("UPDATE delivery_milestones SET state = 'completed',completed_at = UTC_TIMESTAMP(6) WHERE company_id = ? AND handover_id = ? AND id = ?",
            [$company, $handover, $id]);
        return ['milestone_id' => $id];
    }

    private function milestone(int $company, int $handover, int $id): array
    {
        $item = $this->db->row('SELECT id,due_on,state FROM delivery_milestones WHERE company_id = ? AND handover_id = ? AND id = ?',
            [$company, $handover, $id]) ?? throw new BusinessError('此交接下未找到该里程碑。', 404);
        if ($item['state'] === 'completed') {
            throw new BusinessError('已完成的里程碑不能再次变更。', 409);
        }
        return $item;
    }

    private function openIssue(Access $actor, int $company, int $handover, string $title, ?int $delay = null): int
    {
        return $this->db->insert('INSERT INTO delivery_issues (company_id,handover_id,delay_id,title,created_by) VALUES (?,?,?,?,?)',
            [$company, $handover, $delay, Input::text($title, '待确认事项', 240), $actor->userId]);
    }

    private function resolve(Access $actor, int $company, int $handover, int $id, string $resolution): array
    {
        $resolution = Input::text($resolution, '处理结果', 400);
        $result = $this->db->run('UPDATE delivery_issues SET resolution = ?,resolved_by = ?,resolved_at = UTC_TIMESTAMP(6)
            WHERE company_id = ? AND handover_id = ? AND id = ? AND resolved_at IS NULL', [$resolution, $actor->userId, $company, $handover, $id]);
        if ($result->rowCount() !== 1) {
            throw new BusinessError('待确认事项已处理或不在此交接下。', 409);
        }
        return ['issue_id' => $id];
    }
}
