<?php

declare(strict_types=1);

namespace CustomerSuite;

final class ReportCapture
{
    public function __construct(private readonly Database $db)
    {
    }

    public function capture(Access $actor, int $anchor, array $data): array
    {
        $actor->allow($anchor, 'manager');
        $label = Input::text($data['label'], '报表标题', 120);
        $scope = $this->companies($actor, $anchor, $data['company_ids'] ?? []);
        $fx = (new ReportRates($this->db))->record($anchor, $data['fx_set_id']);
        $now = $this->db->row('SELECT UTC_TIMESTAMP(6) AS instant')['instant'];
        $at = isset($data['as_of']) ? Input::instant($data['as_of']) : $now;
        $start = isset($data['cohort_start']) ? Input::date($data['cohort_start']) : null;
        $day = substr($at, 0, 10);
        if ($at > $now || ($start !== null && $start > $day) || $fx['rate_date'] > $day) {
            throw new BusinessError('统计时点不得在未来，业务起始日及汇率日期不得晚于该时点。');
        }
        return $this->db->transaction(function () use ($actor, $anchor, $label, $scope, $fx, $at, $start): array {
            $id = $this->db->insert(
                'INSERT INTO report_snapshots (anchor_id,label,as_of,cohort_start,fx_set_id,captured_by) VALUES (?,?,?,?,?,?)',
                [$anchor, $label, $at, $start, $fx['id'], $actor->userId],
            );
            $this->db->insertRows('INSERT INTO report_companies (report_id,company_id)',
                array_map(static fn (int $company): array => [$id, $company], $scope));
            $count = $this->db->run(self::MATERIALIZE, [$id])->rowCount();
            $missing = $this->db->rows(
                'SELECT DISTINCT currency FROM report_rows WHERE report_id = ? AND fx_rate IS NULL ORDER BY currency', [$id],
            );
            if ($missing !== []) {
                throw new BusinessError('当前汇率版本缺少币种：' . implode('、', array_column($missing, 'currency')) . '。', 422);
            }
            (new Audit($this->db))->record($actor, $anchor, 'report', $id, 1, 'report.capture',
                ['companies' => $scope, 'as_of' => $at, 'fx_set_id' => $fx['id'], 'row_count' => $count]);
            return ['id' => $id, 'version' => 1, 'row_count' => $count, 'as_of' => $at, 'fx_set_id' => $fx['id']];
        });
    }

    private function companies(Access $actor, int $anchor, array $selection): array
    {
        if (!array_is_list($selection) || count($selection) > 30) {
            throw new BusinessError('请选择最多 30 家报表公司。');
        }
        $ids = $selection === [] ? [$anchor] : array_map(static fn (mixed $id): int => Input::id($id, '报表公司'), $selection);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $actor->allow($id, 'manager');
            if ($id !== $anchor && $actor->company($id)['parent_id'] !== $anchor) {
                throw new BusinessError('仅可选择当前组织及具备经理权限的直属公司。');
            }
        }
        return $ids;
    }

    private const MATERIALIZE = <<<'SQL'
        INSERT INTO report_rows (report_id,company_id,kind,object_id,account_id,source_version,title,
            stage,stage_event_id,furthest_step,amount_version,currency,value_minor,fx_rate,cny_minor,
            first_touch_id,first_channel,recent_touch_id,recent_channel)
        WITH scope AS (
            SELECT r.id,r.anchor_id,r.as_of,r.cohort_start,r.fx_set_id,c.company_id
            FROM report_snapshots r JOIN report_companies c ON c.report_id = r.id WHERE r.id = ?
        ), facts AS (
            SELECT s.id AS report_id,o.company_id,'opportunity' AS kind,o.id AS object_id,o.account_id,
                h.opportunity_version AS source_version,o.title,h.to_stage AS stage,h.id AS stage_event_id,
                (SELECT MAX(FIELD(p.to_stage,'discovery','qualified','proposal','negotiation','won'))
                 FROM stage_history p WHERE p.company_id = o.company_id AND p.opportunity_id = o.id
                    AND p.opportunity_version <= h.opportunity_version) AS furthest_step,
                b.opportunity_version AS amount_version,o.currency,b.value_minor,s.anchor_id,s.fx_set_id,
                (SELECT t.id FROM lead_touches t
                 WHERE t.company_id = o.company_id AND t.lead_id = o.lead_id
                    AND t.occurred_at <= s.as_of AND t.imported_at <= s.as_of
                 ORDER BY t.occurred_at,t.id LIMIT 1) AS first_touch_id,
                (SELECT t.id FROM lead_touches t
                 WHERE t.company_id = o.company_id AND t.lead_id = o.lead_id
                    AND t.occurred_at <= s.as_of AND t.imported_at <= s.as_of
                 ORDER BY t.occurred_at DESC,t.id DESC LIMIT 1) AS recent_touch_id
            FROM scope s JOIN opportunities o ON o.company_id = s.company_id AND o.created_at <= s.as_of
                AND (s.cohort_start IS NULL OR o.created_at >= s.cohort_start)
            JOIN stage_history h ON h.company_id = o.company_id AND h.opportunity_id = o.id
                AND h.opportunity_version = (
                    SELECT k.opportunity_version FROM stage_history k
                    WHERE k.company_id = o.company_id AND k.opportunity_id = o.id AND k.occurred_at <= s.as_of
                    ORDER BY k.opportunity_version DESC LIMIT 1
                )
            LEFT JOIN opportunity_amount_history b ON b.company_id = o.company_id AND b.opportunity_id = o.id
                AND b.opportunity_version = (
                    SELECT m.opportunity_version FROM opportunity_amount_history m
                    WHERE m.company_id = o.company_id AND m.opportunity_id = o.id AND m.recorded_at <= s.as_of
                    ORDER BY m.recorded_at DESC,m.opportunity_version DESC LIMIT 1
                )
            UNION ALL
            SELECT s.id,a.company_id,'agreement',a.id,a.account_id,v.revision,
                CONCAT(a.reference_no,' · ', LEFT(a.legal_name,130)),NULL,NULL,NULL,v.revision,v.currency,v.value_minor,
                s.anchor_id,s.fx_set_id,NULL,NULL
            FROM scope s JOIN agreements a ON a.company_id = s.company_id
            JOIN agreement_versions v ON v.company_id = a.company_id AND v.agreement_id = a.id AND v.revision = (
                SELECT k.revision FROM agreement_versions k
                WHERE k.company_id = a.company_id AND k.agreement_id = a.id AND k.decision = 'signed' AND k.decided_at <= s.as_of
                ORDER BY k.revision DESC LIMIT 1
            )
            JOIN agreement_versions initial ON initial.company_id = a.company_id AND initial.agreement_id = a.id AND initial.revision = 1
                AND (s.cohort_start IS NULL OR initial.decided_at >= s.cohort_start)
        )
        SELECT f.report_id,f.company_id,f.kind,f.object_id,f.account_id,f.source_version,f.title,
            f.stage,f.stage_event_id,f.furthest_step,f.amount_version,f.currency,f.value_minor,x.rate,
            ROUND(CAST(f.value_minor AS DECIMAL(24,0)) * x.rate * IF(f.currency IN ('JPY','KRW'),100,1),0),
            f.first_touch_id,fc.channel,f.recent_touch_id,rc.channel
        FROM facts f LEFT JOIN report_fx_rates x
            ON x.company_id = f.anchor_id AND x.set_id = f.fx_set_id AND x.currency = f.currency
        LEFT JOIN lead_touches ft ON ft.company_id = f.company_id AND ft.id = f.first_touch_id
        LEFT JOIN campaigns fc ON fc.company_id = ft.company_id AND fc.id = ft.campaign_id
        LEFT JOIN lead_touches rt ON rt.company_id = f.company_id AND rt.id = f.recent_touch_id
        LEFT JOIN campaigns rc ON rc.company_id = rt.company_id AND rc.id = rt.campaign_id
        SQL;
}
