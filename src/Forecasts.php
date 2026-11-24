<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Forecasts
{
    public function __construct(private readonly Database $db)
    {
    }

    public function capture(Access $actor, int $company, array $input): array
    {
        $actor->allow($company, 'manager');
        $label = Input::text($input['label'] ?? null, '快照标题', 120);
        $start = Input::date($input['period_start'] ?? null);
        $end = Input::date($input['period_end'] ?? null);
        if ($end < $start) {
            throw new BusinessError('预测结束日须晚于或等于起始日。');
        }
        return $this->db->transaction(function () use ($actor, $company, $label, $start, $end): array {
            $id = $this->db->insert(
                'INSERT INTO forecast_snapshots (company_id,created_by,label,period_start,period_end) VALUES (?,?,?,?,?)',
                [$company, $actor->userId, $label, $start, $end],
            );
            $copied = $this->db->run(
                'INSERT INTO forecast_rows (company_id,snapshot_id,opportunity_id,account_id,owner_id,opportunity_version,
                 title,stage,currency,value_minor,probability_bps,weighted_minor,expected_close)
                 SELECT company_id,?,id,account_id,owner_id,version,title,stage,currency,value_minor,probability_bps,
                 (value_minor * probability_bps + 5000) DIV 10000,expected_close
                 FROM opportunities WHERE company_id = ? AND expected_close BETWEEN ? AND ?',
                [$id, $company, $start, $end],
            )->rowCount();
            (new Audit($this->db))->record($actor, $company, 'forecast', $id, 1, 'forecast.capture', ['opportunity_count' => $copied]);
            return ['id' => $id, 'version' => 1, 'opportunity_count' => $copied];
        });
    }

    public function list(Access $actor, int $company, int $before = PHP_INT_MAX): array
    {
        $actor->allow($company, 'manager');
        return $this->db->rows(
            'SELECT id,label,period_start,period_end,captured_at FROM forecast_snapshots
             WHERE company_id = ? AND id < ? ORDER BY id DESC LIMIT 50', [$company, $before],
        );
    }

    public function detail(Access $actor, int $company, int $id, int $after = 0): array
    {
        $manager = $this->canRead($actor, $company, $id);
        $filter = $manager ? '' : ' AND r.owner_id = ? AND EXISTS (
            SELECT 1 FROM accounts a WHERE a.company_id = r.company_id AND a.id = r.account_id AND a.owner_id = ?
        )';
        $params = [$company, $id, ...($manager ? [] : [$actor->userId, $actor->userId])];
        $snapshot = $this->snapshot($company, $id);
        $snapshot['items'] = $this->db->rows(
            'SELECT r.* FROM forecast_rows r WHERE r.company_id = ? AND r.snapshot_id = ?' . $filter .
            ' AND r.opportunity_id > ? ORDER BY r.opportunity_id LIMIT 100', [...$params, $after],
        );
        $snapshot['totals'] = $this->db->rows(
            'SELECT r.currency,COUNT(*) AS opportunity_count,SUM(r.value_minor) AS value_minor,SUM(r.weighted_minor) AS weighted_minor
             FROM forecast_rows r WHERE r.company_id = ? AND r.snapshot_id = ?' . $filter . ' GROUP BY r.currency ORDER BY r.currency',
            $params,
        );
        return $snapshot;
    }

    public function compare(Access $actor, int $company, int $first, int $second, int $after = 0): array
    {
        $actor->allow($company, 'manager');
        $old = $this->snapshot($company, $first);
        $new = $this->snapshot($company, $second);
        if ($old['captured_at'] > $new['captured_at'] || $first === $second) {
            throw new BusinessError('请选择两个不同快照，并按时间先后排列。');
        }
        return ['from' => $old, 'to' => $new, 'changes' => $this->db->rows(
            'WITH changed AS (
                SELECT a.opportunity_id, a.title AS previous_title, b.title AS current_title,
                 a.stage AS previous_stage, b.stage AS current_stage, a.currency AS previous_currency, b.currency AS current_currency,
                 a.value_minor AS previous_value_minor, b.value_minor AS current_value_minor,
                 a.weighted_minor AS previous_weighted_minor, b.weighted_minor AS current_weighted_minor
                 FROM forecast_rows a LEFT JOIN forecast_rows b
                 ON b.company_id = a.company_id AND b.opportunity_id = a.opportunity_id AND b.snapshot_id = ?
                 WHERE a.company_id = ? AND a.snapshot_id = ? AND a.opportunity_id > ?
                 AND (b.opportunity_id IS NULL OR a.opportunity_version <> b.opportunity_version)
                UNION ALL
                SELECT b.opportunity_id,NULL,b.title,NULL,b.stage,NULL,b.currency,NULL,b.value_minor,NULL,b.weighted_minor
                 FROM forecast_rows b LEFT JOIN forecast_rows a
                 ON a.company_id = b.company_id AND a.opportunity_id = b.opportunity_id AND a.snapshot_id = ?
                 WHERE b.company_id = ? AND b.snapshot_id = ? AND b.opportunity_id > ? AND a.opportunity_id IS NULL
            ) SELECT * FROM changed ORDER BY opportunity_id LIMIT 100', [$second, $company, $first, $after, $first, $company, $second, $after],
        )];
    }

    private function canRead(Access $actor, int $company, int $id): bool
    {
        $actor->allow($company, 'manager', 'sales');
        if ($actor->has($company, 'manager')) {
            return true;
        }
        if (!$this->db->row(
            'SELECT r.opportunity_id FROM forecast_rows r JOIN accounts a ON a.company_id = r.company_id AND a.id = r.account_id
             WHERE r.company_id = ? AND r.snapshot_id = ? AND r.owner_id = ? AND a.owner_id = ? LIMIT 1',
            [$company, $id, $actor->userId, $actor->userId],
        )) {
            throw new BusinessError('未找到预测快照。', 404);
        }
        return false;
    }

    private function snapshot(int $company, int $id): array
    {
        return $this->db->row(
            'SELECT id,label,period_start,period_end,captured_at,created_by FROM forecast_snapshots WHERE company_id = ? AND id = ?',
            [$company, $id],
        ) ?? throw new BusinessError('未找到预测快照。', 404);
    }
}
