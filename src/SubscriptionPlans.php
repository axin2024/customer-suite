<?php

declare(strict_types=1);

namespace CustomerSuite;

final class SubscriptionPlans
{
    public function __construct(private readonly Database $db)
    {
    }

    public function publish(Access $actor, int $company, array $data): array
    {
        $actor->allow($company, 'manager');
        $code = Input::key($data['code'], '套餐编码', 64);
        $name = Input::text($data['name'], '套餐名称', 160);
        $day = Input::date($data['effective_on']);
        $currency = Money::currency($data['currency']);
        $monthly = Money::minor($data['monthly_fee'], '月费', $currency);
        $level = Input::choice($data['service_level'], '服务等级', ['standard','priority','critical']);
        $metrics = $data['metrics'];
        if (!is_array($metrics) || !array_is_list($metrics) || !$metrics || count($metrics) > 100) {
            throw new BusinessError('每个版本须定义 1 至 100 项计量权益。');
        }
        $values = [];
        foreach ($metrics as $row) {
            $metric = Input::key($row['metric'] ?? null, '计量项', 64);
            if (isset($values[$metric])) {
                throw new BusinessError('同一版本不能重复定义计量项。');
            }
            $values[$metric] = [$metric, Input::integer($row['included_units'] ?? null, '免费额度', 0, 1000000000),
                Money::minor($row['overage_fee'] ?? null, '超量单价', $currency)];
        }
        return $this->db->transaction(function () use ($actor,$company,$code,$name,$day,$currency,$monthly,$level,$values,$data): array {
            $this->db->run('INSERT INTO subscription_plans (company_id,code) VALUES (?,?)
                ON DUPLICATE KEY UPDATE id=id', [$company,$code]);
            $plan = $this->db->row('SELECT id FROM subscription_plans WHERE company_id=? AND code=? FOR UPDATE', [$company,$code])['id'];
            $last = $this->db->row('SELECT revision,effective_on FROM subscription_rates WHERE company_id=? AND plan_id=? ORDER BY revision DESC LIMIT 1', [$company,$plan]);
            $previous = $last['revision'] ?? 0;
            if ($previous !== $data['previous_revision'] || ($last && $last['effective_on'] >= $day)) {
                throw new BusinessError('套餐版本已变更，或生效日未晚于上一版本。', 409);
            }
            $revision = $previous + 1;
            $this->db->run('INSERT INTO subscription_rates (company_id,plan_id,revision,effective_on,name,currency,monthly_minor,service_level,published_by)
                VALUES (?,?,?,?,?,?,?,?,?)', [$company,$plan,$revision,$day,$name,$currency,$monthly,$level,$actor->userId]);
            foreach ($values as $value) {
                $this->db->run('INSERT INTO subscription_rate_metrics (company_id,plan_id,revision,metric,included_units,overage_minor) VALUES (?,?,?,?,?,?)',
                    [$company,$plan,$revision,...$value]);
            }
            $result = ['plan_id'=>$plan,'entitlement_rev'=>$revision,'effective_on'=>$day];
            (new Audit($this->db))->record($actor,$company,'subscription_plan',$plan,$revision,'plan.publish',$result);
            return $result;
        });
    }

    public function onDate(Access $actor, int $company, int $plan, string $date): array
    {
        $actor->allow($company, 'manager','sales','service','success');
        $day = Input::date($date);
        $rate = $this->db->row('SELECT p.code,r.plan_id,r.revision AS entitlement_rev,r.effective_on,r.name,r.currency,r.monthly_minor,r.service_level
            FROM subscription_plans p JOIN subscription_rates r ON r.company_id=p.company_id AND r.plan_id=p.id
            WHERE p.company_id=? AND p.id=? AND r.effective_on<=? ORDER BY r.effective_on DESC LIMIT 1', [$company,$plan,$day]);
        if (!$rate) {
            throw new BusinessError('指定日期没有可用的套餐版本。', 404);
        }
        $rate['metrics'] = $this->db->rows('SELECT metric,included_units,overage_minor FROM subscription_rate_metrics
            WHERE company_id=? AND plan_id=? AND revision=? ORDER BY metric', [$company,$plan,$rate['entitlement_rev']]);
        return $rate;
    }
}
