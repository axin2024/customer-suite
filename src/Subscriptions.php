<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class Subscriptions
{
    public function __construct(private readonly Database $db)
    {
    }

    public function authorizeSales(Access $actor, int $company, array $contract): void
    {
        $actor->allow($company, 'sales', 'manager');
        if (!$actor->has($company,'manager')) {
            $owner = $this->db->row('SELECT owner_id FROM accounts WHERE company_id=? AND id=?', [$company,$contract['account_id']]);
            if ($contract['owner_id'] !== $actor->userId || $owner['owner_id'] !== $actor->userId) {
                throw new BusinessError('未找到获授权的销售合同。',404);
            }
        }
    }

    public function open(Access $actor, int $company, array $data): array
    {
        $start = Input::date($data['term_start']);
        $end = Input::date($data['term_end']);
        $zone = Input::choice($data['billing_timezone'], '计费时区', DateTimeZone::listIdentifiers());
        $mode = Input::choice($data['mode'],'开通方式',['trial','paid']);
        $seats = Input::integer($data['seats'],'席位数',1,1000000);
        return $this->db->transaction(function () use ($actor,$company,$data,$start,$end,$zone,$mode,$seats): array {
            $agreements = new Agreements($this->db);
            $contract = $agreements->subject($company,$data['agreement_id'],true);
            $this->authorizeSales($actor,$company,$contract);
            $target = $data['service_company_id'];
            if ($target !== $company) {
                $actor->allow($target,'manager');
            }
            $account = (new Accounts($this->db))->lockCurrent($target,$data['service_account_id']);
            if ($account['id'] !== $data['service_account_id'] || $account['state'] !== 'active' ||
                !(($target === $company && $account['id'] === $contract['account_id']) ||
                ($account['parent_company_id'] === $company && $account['parent_id'] === $contract['account_id']))) {
                throw new BusinessError('服务对象须为签约客户或其有效直属子客户。',422);
            }
            $key = [$company,$contract['id'],$data['line_no'],$target,$account['id']];
            $digest = Input::digest($data);
            $prior = $this->db->row('SELECT * FROM subscriptions WHERE company_id=? AND agreement_id=? AND line_no=?
                AND service_company_id=? AND service_account_id=? AND term_start=?', [...$key,$start]);
            if ($prior !== null) {
                if (!hash_equals($prior['opening_digest'],$digest)) {
                    throw new BusinessError('此服务对象已按其他条件开通。',409);
                }
                return $this->receipt($prior);
            }
            (new SubscriptionPilot($this->db))->accepting($company);
            $rate = $agreements->revision($company,$contract['id'],$contract['active_revision']);
            if ($data['agreement_revision'] !== $contract['active_revision'] || $rate['decision'] !== 'signed' || $contract['state'] === 'ended') {
                throw new BusinessError('合同已终止或签署版本发生变化。',409);
            }
            if ($start >= $end || $start < $rate['starts_on'] || (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d') > $rate['ends_on']) {
                throw new BusinessError('订阅起止日须处于合同服务期内。');
            }
            $line = $this->db->row('SELECT product_code,quantity FROM quote_lines WHERE company_id=? AND quote_id=? AND revision=? AND line_no=?',
                [$contract['source_company_id'],$contract['quote_id'],$contract['quote_revision'],$data['line_no']]);
            $price = (new SubscriptionPlans($this->db))->onDate($actor,$company,$data['plan_id'],substr($rate['decided_at'],0,10));
            if (!$line || $price['code'] !== $line['product_code'] || $price['currency'] !== $rate['currency'] || $price['entitlement_rev'] !== $data['entitlement_rev']) {
                throw new BusinessError('套餐须匹配已签行项目的产品、币种和签约日价格版本。',409);
            }
            $overlap = $this->db->rows('SELECT term_start, COALESCE((SELECT MIN(a.effective_on) FROM subscription_amendments a
                WHERE a.subscription_id=s.id AND a.company_id=s.company_id AND a.kind=\'cancel\'),term_end) term_end,seats,service_company_id,service_account_id FROM subscriptions s
                WHERE company_id=? AND agreement_id=? AND line_no=? AND term_start<? AND term_end>?',
                [$company,$contract['id'],$data['line_no'],$end,$start]);
            $changes = [$start=>$seats];
            foreach ($overlap as $other) {
                if ($other['term_end'] <= $start) {
                    continue;
                }
                if ($other['service_company_id'] === $target && $other['service_account_id'] === $account['id']) {
                    throw new BusinessError('服务对象已有重叠的订阅期限。',409);
                }
                $from = max($start,$other['term_start']);
                $until = min($end,$other['term_end']);
                $changes[$from] = ($changes[$from] ?? 0) + $other['seats'];
                $changes[$until] = ($changes[$until] ?? 0) - $other['seats'];
            }
            ksort($changes);
            $allocated = 0;
            foreach ($changes as $delta) {
                $allocated += $delta;
                if ($allocated > $line['quantity']) {
                    throw new BusinessError('同日分配席位超过已签行项目数量。',409);
                }
            }
            $id = $this->db->insert('INSERT INTO subscriptions (company_id,agreement_id,agreement_revision,source_company_id,quote_id,
                quote_revision,line_no,payer_id,service_company_id,service_account_id,seats,plan_id,entitlement_rev,term_start,term_end,paid_from,
                billing_timezone,opening_digest,opened_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$company,$contract['id'],$contract['active_revision'],$contract['source_company_id'],$contract['quote_id'],$contract['quote_revision'],
                $data['line_no'],$contract['account_id'],$target,$account['id'],$seats,$price['plan_id'],$price['entitlement_rev'],$start,$end,
                $mode === 'paid' ? $start : null,$zone,$digest,$actor->userId]);
            $result = $this->receipt($this->subject($company,$id));
            (new Audit($this->db))->record($actor,$company,'subscription',$id,1,'subscription.open',$result);
            return $result;
        });
    }

    public function convert(Access $actor, int $company, array $data): array
    {
        $day = Input::date($data['paid_from']);
        return $this->db->transaction(function () use ($actor,$company,$data,$day): array {
            $row = $this->subject($company,$data['id'],true);
            $contract = (new Agreements($this->db))->subject($company,$row['agreement_id']);
            $this->authorizeSales($actor,$company,$contract);
            (new SubscriptionPilot($this->db))->accepting($company);
            Accounts::checkVersion($row,$data['version']);
            $terms = (new SubscriptionTerms($this->db))->timeline($row);
            if (SubscriptionTerms::onDay($terms,$day)['kind'] === 'cancel' || $contract['state'] === 'ended' || $row['paid_from'] !== null || $day < $row['term_start'] || $day >= $row['term_end']) {
                throw new BusinessError('只有合约期内的试用订阅可转付费。',409);
            }
            $this->db->run('UPDATE subscriptions SET paid_from=?,version=version+1 WHERE company_id=? AND id=?',[$day,$company,$row['id']]);
            $result = $this->receipt($this->subject($company,$row['id']));
            (new Audit($this->db))->record($actor,$company,'subscription',$row['id'],$result['version'],'subscription.convert',$result);
            return $result;
        });
    }

    public function subject(int $company, int $id, bool $lock = false): array
    {
        return $this->db->row('SELECT * FROM subscriptions WHERE company_id=? AND id=?'.($lock ? ' FOR UPDATE' : ''),[$company,$id])
            ?? throw new BusinessError('未找到此订阅。',404);
    }

    public function readable(Access $actor, int $company, int $id): array
    {
        return $this->visible($actor,$company,$id,null)[0] ?? throw new BusinessError('未找到此订阅。',404);
    }

    public function canReadBilling(Access $actor, int $company, int $id): bool
    {
        return $this->visible($actor,$company,$id,null,false) !== [];
    }

    public function register(Access $actor, int $company, int $before): array
    {
        $rows = $this->visible($actor,$company,null,$before);
        $more = count($rows) > 50;
        $items = array_slice($rows,0,50);
        return ['items'=>array_map(fn(array $row): array => $this->receipt($row)+['payer'=>$row['payer'],'beneficiary'=>$row['beneficiary']],$items),
            'next_before'=>$more ? end($items)['id'] : null];
    }

    private function visible(Access $actor,int $company,?int $id,?int $before,bool $meterAccess = true): array
    {
        $actor->company($company);
        $sql = 'SELECT s.*,p.name payer,b.name beneficiary FROM subscriptions s JOIN agreements a ON a.company_id=s.company_id AND a.id=s.agreement_id
            JOIN accounts p ON p.company_id=s.company_id AND p.id=s.payer_id JOIN accounts b ON b.company_id=s.service_company_id AND b.id=s.service_account_id
            WHERE s.company_id=? AND (? OR EXISTS (SELECT 1 FROM memberships m WHERE m.company_id=s.company_id AND m.user_id=? AND
            (m.role IN (\'manager\',\'finance\') OR (m.role=\'meter\' AND ?) OR (m.role=\'sales\' AND a.owner_id=m.user_id AND p.owner_id=m.user_id)
            OR (m.role=\'success\' AND EXISTS (SELECT 1 FROM success_assignments x WHERE x.company_id=s.company_id AND x.agreement_id=a.id AND x.owner_id=m.user_id))
            OR (m.role=\'service\' AND EXISTS (SELECT 1 FROM service_contract_grants g WHERE g.company_id=s.company_id AND g.agreement_id=a.id AND g.user_id=m.user_id))
            OR (m.role=\'subscriber\' AND EXISTS (SELECT 1 FROM subscription_viewers v WHERE v.company_id=s.company_id AND v.subscription_id=s.id AND v.user_id=m.user_id)))))';
        $params = [$company,(int)$actor->user()['is_admin'],$actor->userId,(int)$meterAccess];
        return $id !== null ? $this->db->rows($sql.' AND s.id=?',[...$params,$id])
            : $this->db->rows($sql.' AND s.id<? ORDER BY s.id DESC LIMIT 51',[...$params,$before]);
    }

    public function detail(Access $actor, int $company, int $id, string $date): array
    {
        $day = Input::date($date);
        $row = $this->readable($actor,$company,$id);
        $timeline = (new SubscriptionTerms($this->db))->timeline($row);
        $rate = SubscriptionTerms::onDay($timeline,$day);
        $active = $day >= $row['term_start'] && $day < $row['term_end'] && $rate['kind'] !== 'cancel';
        $state = $day < $row['term_start'] ? 'scheduled' : ($day >= $row['term_end'] ? 'expired' :
            ($row['paid_from'] !== null && $day >= $row['paid_from'] ? 'active' : 'trial'));
        if ($rate['kind'] === 'cancel') {
            $state = 'cancelled';
        }
        $metrics = $this->db->rows('SELECT metric,included_units,overage_minor FROM subscription_rate_metrics WHERE company_id=? AND plan_id=? AND revision=? ORDER BY metric',
            [$company,$rate['plan_id'],$rate['entitlement_rev']]);
        $usage = $this->db->rows('SELECT u.metric,SUM(u.units-COALESCE((SELECT SUM(c.units) FROM subscription_usage_credits c
            WHERE c.company_id=u.company_id AND c.usage_id=u.id),0)) used_units FROM subscription_usage u
            WHERE u.company_id=? AND u.subscription_id=? AND u.billing_period=? AND u.local_date<=? GROUP BY u.metric',
            [$company,$id,substr($day,0,7),$day]);
        $used = array_column($usage,'used_units','metric');
        foreach ($metrics as &$metric) {
            $metric['used_units'] = (int)($used[$metric['metric']] ?? 0);
            $metric['balance_units'] = $active ? max(0,$metric['included_units']-$metric['used_units']) : 0;
        }
        $canReview = $actor->has($company,'manager','finance','service');
        $statements = !$this->canReadBilling($actor,$company,$id) ? [] : $this->db->rows("SELECT id,billing_period,revision,state,total_minor,currency FROM subscription_statements
            WHERE company_id=? AND subscription_id=? AND (state='locked' OR ?) ORDER BY billing_period DESC LIMIT 60",[$company,$id,(int)$canReview]);
        return array_replace($this->receipt($row),array_intersect_key($rate,array_flip(['plan_id','entitlement_rev','name','currency','monthly_minor','service_level'])))
            + ['state'=>$state,'date'=>$day,'metrics'=>$metrics,'payer'=>$row['payer'],'beneficiary'=>$row['beneficiary'],
                'changes'=>array_slice($timeline,1),'statements'=>$statements,'fixed_forecast_minor'=>SubscriptionTerms::fixedFee($row,$timeline,substr($day,0,7)),
                'next_fixed_minor'=>SubscriptionTerms::fixedFee($row,$timeline,substr(SubscriptionTerms::month(substr($day,0,7))[1],0,7))];
    }

    private function receipt(array $row): array
    {
        return ['subscription_id'=>$row['id'],'agreement_id'=>$row['agreement_id'],'agreement_revision'=>$row['agreement_revision'],
            'payer_id'=>$row['payer_id'],'service_company_id'=>$row['service_company_id'],'service_account_id'=>$row['service_account_id'],
            'line_no'=>$row['line_no'],'seats'=>$row['seats'],'plan_id'=>$row['plan_id'],'entitlement_rev'=>$row['entitlement_rev'],
            'term_start'=>$row['term_start'],'term_end'=>$row['term_end'],'billing_timezone'=>$row['billing_timezone'],
            'paid_from'=>$row['paid_from'],'version'=>$row['version']];
    }

    public function grantViewer(Access $actor, int $company, array $data): array
    {
        $actor->allow($company,'manager');
        $row = $this->subject($company,$data['id'],true);
        if ($data['allowed']) {
            $actor->eligible($company,$data['user_id'],'subscriber');
            $this->db->run('INSERT INTO subscription_viewers (company_id,subscription_id,user_id,granted_by) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE granted_by=?',[$company,$row['id'],$data['user_id'],$actor->userId,$actor->userId]);
        } else {
            $this->db->run('DELETE FROM subscription_viewers WHERE company_id=? AND subscription_id=? AND user_id=?',[$company,$row['id'],$data['user_id']]);
        }
        $result = ['subscription_id'=>$row['id'],'user_id'=>$data['user_id'],'allowed'=>$data['allowed']];
        (new Audit($this->db))->record($actor,$company,'subscription',$row['id'],$row['version'],'subscription.viewer',$result);
        return $result;
    }
}
