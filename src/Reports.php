<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Reports
{
    public const KINDS = ['opportunity' => '商机', 'agreement' => '合同'];

    public function __construct(private readonly Database $db)
    {
    }

    public function list(Access $actor, int $company, int $before = PHP_INT_MAX): array
    {
        $actor->allow($company, 'manager', 'sales');
        $manager = (int) $actor->has($company, 'manager');
        return $this->db->rows(
            'SELECT r.id,r.as_of,r.cohort_start,r.captured_at,IF(r.anchor_id = ? AND ? = 1,r.label,NULL) AS label
             FROM report_snapshots r WHERE r.id < ? AND
                ((r.anchor_id = ? AND ? = 1) OR EXISTS (
                    SELECT 1 FROM report_companies c WHERE c.report_id = r.id AND c.company_id = ?))
             ORDER BY r.id DESC LIMIT 50',
            [$company, $manager, $before, $company, $manager, $company],
        );
    }

    public function detail(Access $actor, int $company, int $id, array $query): array
    {
        [$snapshot, $scopes, $sql, $params] = $this->source($actor, $company, $id, $query);
        $totals = $this->db->rows(
            'SELECT r.company_id,r.kind,r.stage,COUNT(*) AS record_count,COUNT(r.cny_minor) AS valued_count,
                SUM(r.cny_minor) AS cny_minor' . $sql . ' GROUP BY r.company_id,r.kind,r.stage ORDER BY r.company_id,r.kind,r.stage',
            $params,
        );
        $groupTotals = $this->db->rows(
            'SELECT r.kind,COUNT(*) AS record_count,COUNT(r.cny_minor) AS valued_count,SUM(r.cny_minor) AS cny_minor'
            . $sql . ' GROUP BY r.kind ORDER BY r.kind', $params,
        );
        $funnel = $this->db->rows(
            "SELECT r.company_id,COUNT(*) AS entered,SUM(r.furthest_step >= 2) AS qualified,SUM(r.furthest_step >= 3) AS proposal,
                SUM(r.furthest_step >= 4) AS negotiation,SUM(r.furthest_step = 5) AS won,SUM(r.stage = 'lost') AS lost"
             . $sql . " AND r.kind = 'opportunity' GROUP BY r.company_id ORDER BY r.company_id", $params,
        );
        $channels = [];
        foreach (['first' => 'first_channel', 'recent' => 'recent_channel'] as $key => $column) {
            $channels[$key] = $this->db->rows(
                "SELECT r.company_id,r.$column AS channel,COUNT(*) AS opportunities,
                    SUM(r.stage = 'won') AS won,COUNT(CASE WHEN r.stage = 'won' THEN r.cny_minor END) AS valued_wins,
                    SUM(CASE WHEN r.stage = 'won' THEN r.cny_minor END) AS won_cny_minor"
                . $sql . " AND r.kind = 'opportunity' GROUP BY r.company_id,r.$column ORDER BY r.company_id,r.$column",
                $params,
            );
        }
        $after = isset($query['after']) ? Input::id($query['after'], '分页标记') : 0;
        $afterCompany = isset($query['after_company']) ? Input::id($query['after_company']) : 0;
        $afterKind = isset($query['after_kind']) ? Input::choice($query['after_kind'], '记录类型', array_keys(self::KINDS)) : null;
        if (($after > 0 || $afterCompany > 0 || $afterKind !== null) && !($after > 0 && $afterCompany > 0 && $afterKind !== null)) {
            throw new BusinessError('分页标记缺少公司、记录种类或对象编号。');
        }
        $page = $afterKind === null ? '' :
            ' AND (r.company_id > ? OR (r.company_id = ? AND (CAST(r.kind AS CHAR) > ?
                OR (r.kind = ? AND r.object_id > ?))))';
        $items = $this->db->rows(self::COLUMNS . $sql . $page . self::ORDER . ' LIMIT 51',
            [...$params, ...($afterKind === null ? [] : [$afterCompany, $afterCompany, $afterKind, $afterKind, $after])]);
        $next = null;
        if (count($items) > 50) {
            array_pop($items);
            $last = $items[array_key_last($items)];
            $next = ['after_company' => $last['company_id'], 'after_kind' => $last['kind'], 'after' => $last['object_id']];
        }
        return $snapshot + ['companies' => array_values($scopes), 'totals' => $totals, 'group_totals' => $groupTotals,
            'funnel' => $funnel, 'channels' => $channels, 'items' => $items, 'next' => $next];
    }

    public function export(Access $actor, int $company, int $id, array $query): iterable
    {
        [, , $sql, $params] = $this->source($actor, $company, $id, $query);
        return $this->db->stream(self::COLUMNS . $sql . self::ORDER, $params);
    }

    public function assertReceipt(Access $actor, int $company, int $id): void
    {
        $actor->allow($company, 'manager');
        foreach ($this->members($id) as $scope) {
            $actor->allow($scope['id'], 'manager');
        }
    }

    private function source(Access $actor, int $company, int $id, array $query): array
    {
        $actor->allow($company, 'manager', 'sales');
        $snapshot = $this->db->row(
            'SELECT r.id,r.anchor_id,r.label,r.as_of,r.cohort_start,r.captured_at,r.fx_set_id,f.rate_date
             FROM report_snapshots r JOIN report_fx_sets f ON f.company_id = r.anchor_id AND f.id = r.fx_set_id
             WHERE r.id = ?', [$id],
        ) ?? throw new BusinessError('未找到经营快照。', 404);
        $anchorView = $snapshot['anchor_id'] === $company && $actor->has($company, 'manager');
        if (!$anchorView) {
            unset($snapshot['anchor_id'], $snapshot['label']);
        }
        $scopes = $this->scope($actor, $company, $id, $anchorView);
        if ($scopes === []) {
            throw new BusinessError('未找到经营快照。', 404);
        }
        $selected = isset($query['scope_company']) ? Input::id($query['scope_company'], '下钻公司') : null;
        if ($selected !== null && !isset($scopes[$selected])) {
            throw new BusinessError('未找到当前授权范围内的公司。', 404);
        }
        $ids = $selected === null ? array_keys($scopes) : [$selected];
        $sql = ' FROM report_rows r JOIN companies c ON c.id = r.company_id
                 WHERE r.report_id = ? AND r.company_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = [$id, ...$ids];
        if (!$actor->has($company, 'manager')) {
            $sql .= " AND EXISTS (SELECT 1 FROM accounts a WHERE a.company_id = r.company_id AND a.id = r.account_id AND a.owner_id = ?)
                AND ((r.kind = 'opportunity' AND EXISTS (SELECT 1 FROM opportunities o
                        WHERE o.company_id = r.company_id AND o.id = r.object_id AND o.owner_id = ?))
                    OR (r.kind = 'agreement' AND EXISTS (SELECT 1 FROM agreements g
                        WHERE g.company_id = r.company_id AND g.id = r.object_id AND g.owner_id = ?)))";
            array_push($params, $actor->userId, $actor->userId, $actor->userId);
        }
        if (isset($query['kind'])) {
            $sql .= ' AND r.kind = ?';
            $params[] = Input::choice($query['kind'], '记录类型', array_keys(self::KINDS));
        }
        if (isset($query['stage'])) {
            $sql .= ' AND r.stage = ?';
            $params[] = Input::choice($query['stage'], '商机阶段', array_keys(Opportunities::WEIGHTS));
        }
        return [$snapshot, $scopes, $sql, $params];
    }

    private function scope(Access $actor, int $company, int $id, bool $anchorView): array
    {
        $scopes = [];
        foreach ($this->members($id) as $item) {
            $branch = $item['id'];
            if ($anchorView ? $actor->has($branch, 'manager') : $branch === $company) {
                $scopes[$branch] = $item;
            }
        }
        return $scopes;
    }

    private function members(int $id): array
    {
        return $this->db->rows(
            'SELECT c.id,c.code,c.name FROM report_companies rc JOIN companies c ON c.id = rc.company_id
             WHERE rc.report_id = ? ORDER BY c.id', [$id],
        );
    }

    private const COLUMNS = 'SELECT r.company_id,c.name AS company_name,r.kind,r.object_id,r.title,r.account_id,r.source_version,
        r.stage,r.stage_event_id,r.furthest_step,r.amount_version,r.currency,r.value_minor,r.fx_rate,r.cny_minor,
        r.first_touch_id,r.first_channel,r.recent_touch_id,r.recent_channel';
    private const ORDER = ' ORDER BY r.company_id,CAST(r.kind AS CHAR),r.object_id';
}
