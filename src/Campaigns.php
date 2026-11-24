<?php

declare(strict_types=1);

namespace CustomerSuite;

use PDOException;

final class Campaigns
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(Access $actor, int $companyId, array $input): array
    {
        $actor->allow($companyId, 'manager', 'marketing');
        $code = strtoupper(Input::key($input['code'] ?? null, '活动代码', 48));
        $name = Input::text($input['name'] ?? null, '活动名称', 160);
        $channel = Input::choice($input['channel'] ?? null, '渠道',
            ['web', 'email', 'event', 'partner', 'direct', 'social']);
        return $this->db->transaction(function () use ($actor, $companyId, $code, $name, $channel): array {
            try {
                $id = $this->db->insert(
                    'INSERT INTO campaigns (company_id,code,name,channel) VALUES (?,?,?,?)', [$companyId, $code, $name, $channel],
                );
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    throw $error;
                }
                throw new BusinessError('活动代码已被使用。', 409);
            }
            (new Audit($this->db))->record($actor, $companyId, 'campaign', $id, 1, 'campaign.create', ['channel' => $channel]);
            return ['id' => $id, 'version' => 1];
        });
    }

    public function list(Access $actor, int $companyId, int $before = PHP_INT_MAX): array
    {
        $actor->allow($companyId, 'manager', 'marketing', 'sales');
        return $this->db->rows(
            'SELECT c.id,c.code,c.name,c.channel,c.version,c.created_at,
             (SELECT COUNT(*) FROM lead_touches t WHERE t.company_id = c.company_id AND t.campaign_id = c.id) AS touch_count
             FROM campaigns c WHERE c.company_id = ? AND c.id < ? ORDER BY c.id DESC LIMIT 50',
            [$companyId, $before],
        );
    }
}
