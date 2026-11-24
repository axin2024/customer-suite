<?php

declare(strict_types=1);

namespace CustomerSuite;

final class HealthSnapshots
{
    public const BANDS = ['healthy' => '健康', 'watch' => '需关注', 'risk' => '有风险'];
    private const MODEL = 1;

    public function __construct(private readonly Database $db)
    {
    }

    public function capture(Access $actor, int $company, int $agreementId): array
    {
        return $this->db->transaction(function () use ($actor, $company, $agreementId): array {
            [$contract, $account] = (new SuccessContracts($this->db))->lock($actor, $company, $agreementId);
            $revision = (new Agreements($this->db))->revision($company, $agreementId, $contract['active_revision']);
            $today = gmdate('Y-m-d');
            $days = (int) (new \DateTimeImmutable($today, new \DateTimeZone('UTC')))
                ->diff(new \DateTimeImmutable($revision['ends_on'], new \DateTimeZone('UTC')))->format('%r%a');
            $service = $this->db->row(
                "SELECT COUNT(*) AS open_count,COALESCE(SUM(escalation_level > 0),0) AS escalated_count
                 FROM service_tickets WHERE company_id = ? AND agreement_id = ? AND state IN ('open','review','waiting')",
                [$company, $agreementId],
            );
            $delivery = $this->db->row(
                'SELECT COUNT(i.id) AS open_count FROM handovers h JOIN delivery_issues i
                    ON i.company_id = h.company_id AND i.handover_id = h.id AND i.resolved_at IS NULL
                 WHERE h.company_id = ? AND h.agreement_id = ?',
                [$company, $agreementId],
            );
            $signals = [
                ['kind' => 'account', 'id' => $account['id'], 'version' => $account['version'], 'state' => $account['state'],
                    'deduction' => $account['state'] === 'frozen' ? 100 : 0],
                ['kind' => 'agreement', 'id' => $agreementId, 'revision' => $revision['revision'], 'state' => $contract['state'],
                    'observed_on' => $today, 'ends_on' => $revision['ends_on'], 'days_remaining' => $days,
                    'deduction' => $contract['state'] === 'ended' || $days < 0 ? 45 : ($days <= 30 ? 15 : 0)],
                ['kind' => 'service', 'agreement_id' => $agreementId, 'open_count' => (int) $service['open_count'],
                    'escalated_count' => (int) $service['escalated_count'],
                    'deduction' => min(35, 3 * $service['open_count'] + 10 * $service['escalated_count'])],
                ['kind' => 'delivery', 'agreement_id' => $agreementId, 'open_count' => (int) $delivery['open_count'],
                    'deduction' => min(25, 5 * $delivery['open_count'])],
            ];
            $score = max(0, 100 - array_sum(array_column($signals, 'deduction')));
            $band = $score >= 80 ? 'healthy' : ($score >= 60 ? 'watch' : 'risk');
            $id = $this->db->insert(
                'INSERT INTO health_snapshots (company_id,account_id,agreement_id,agreement_revision,score,band,signals,model_version,captured_by)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$company, $account['id'], $agreementId, $revision['revision'], $score, $band, Input::json($signals), self::MODEL, $actor->userId],
            );
            (new Audit($this->db))->record($actor, $company, 'agreement', $agreementId, $contract['version'], 'success.health',
                ['snapshot_id' => $id, 'score' => $score, 'band' => $band, 'model_version' => self::MODEL]);
            return ['id' => $id, 'agreement_id' => $agreementId, 'score' => $score, 'band' => $band];
        });
    }

    public function detail(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'success');
        $snapshot = $this->db->row(
            'SELECT h.*,a.reference_no,a.legal_name,u.display_name AS captured_by_name FROM health_snapshots h
             JOIN agreements a ON a.company_id = h.company_id AND a.id = h.agreement_id
             JOIN users u ON u.id = h.captured_by WHERE h.company_id = ? AND h.id = ?', [$company, $id],
        ) ?? throw new BusinessError('未找到健康度记录。', 404);
        (new SuccessContracts($this->db))->readable($actor, $company, $snapshot['agreement_id']);
        $snapshot['signals'] = json_decode($snapshot['signals'], true, 16, JSON_THROW_ON_ERROR);
        return $snapshot;
    }

    public function list(Access $actor, int $company, ?string $band, ?string $cursor, ?int $agreementId = null): array
    {
        $actor->allow($company, 'manager', 'success');
        $manager = $actor->has($company, 'manager');
        if ($band !== null) {
            Input::choice($band, '健康度等级', array_keys(self::BANDS));
        }
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT h.id,h.agreement_id,h.agreement_revision,h.score,h.band,h.model_version,h.captured_at AS updated_at,
                a.reference_no,a.legal_name FROM health_snapshots h
             JOIN agreements a ON a.company_id = h.company_id AND a.id = h.agreement_id
             JOIN success_assignments s ON s.company_id = h.company_id AND s.agreement_id = h.agreement_id
             WHERE h.company_id = ?' . ($manager ? '' : ' AND s.owner_id = ?') .
            ($band === null ? '' : ' AND h.band = ?') . ($agreementId === null ? '' : ' AND h.agreement_id = ?') .
            $page->clause('h', 'captured_at') . ' ORDER BY h.captured_at DESC,h.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, ...($manager ? [] : [$actor->userId]), ...($band === null ? [] : [$band]),
                ...($agreementId === null ? [] : [$agreementId]), ...$page->parameters()],
        ));
    }
}
