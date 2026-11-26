<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class SubscriptionTerms
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function month(string $period): array
    {
        $first = new DateTimeImmutable(Input::date($period.'-01'), new DateTimeZone('UTC'));
        return [$first->format('Y-m-d'),$first->modify('first day of next month')->format('Y-m-d'),(int)$first->format('t')];
    }

    public function timeline(array $sub): array
    {
        $params = [$sub['company_id'],$sub['plan_id'],$sub['entitlement_rev']];
        $rate = $this->db->row('SELECT plan_id,revision AS entitlement_rev,monthly_minor,currency,name,service_level FROM subscription_rates
            WHERE company_id=? AND plan_id=? AND revision=?',$params);
        $start = $rate + ['effective_on'=>$sub['term_start'],'kind'=>'initial','revision'=>0];
        return [$start,...$this->db->rows('SELECT a.id,a.revision,a.effective_on,a.kind,a.reason,r.plan_id,r.revision AS entitlement_rev,
                r.monthly_minor,r.currency,r.name,r.service_level,a.proration_minor FROM subscription_amendments a JOIN subscription_rates r
                ON r.company_id=a.company_id AND r.plan_id=a.plan_id AND r.revision=a.entitlement_rev
                WHERE a.company_id=? AND a.subscription_id=? ORDER BY a.effective_on',[$sub['company_id'],$sub['id']])];
    }

    public static function onDay(array $timeline,string $day): array
    {
        $active = $timeline[0];
        foreach ($timeline as $entry) {
            if ($entry['effective_on'] > $day) {
                break;
            }
            $active = $entry;
        }
        return $active;
    }

    /** Daily weights use local calendar dates. Round once after all fixed-fee segments for the month. */
    public static function fixedFee(array $sub,array $timeline,string $period): int
    {
        [$first,$end,$days] = self::month($period);
        if ($sub['paid_from'] === null) {
            return 0;
        }
        $sum = 0;
        foreach ($timeline as $i=>$rate) {
            $start = max($first,$sub['paid_from'],$sub['term_start'],$rate['effective_on']);
            $stop = min($end,$sub['term_end'],$timeline[$i+1]['effective_on'] ?? $end);
            if ($start < $stop && $rate['kind'] !== 'cancel') {
                $length = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($stop))->days;
                $sum += $length*$rate['monthly_minor'];
            }
        }
        return intdiv($sum+intdiv($days,2),$days);
    }

    public function change(Access $actor,int $company,array $data,bool $preview): array
    {
        return $this->db->transaction(function () use ($actor,$company,$data,$preview): array {
            $subs = new Subscriptions($this->db);
            $sub = $subs->subject($company,$data['id'],true);
            $agreement = (new Agreements($this->db))->subject($company,$sub['agreement_id']);
            $subs->authorizeSales($actor,$company,$agreement);
            Accounts::checkVersion($sub,$data['version']);
            (new SubscriptionPilot($this->db))->accepting($company);
            if ($agreement['state'] === 'ended') {
                throw new BusinessError('已终止合同不能登记新变更。',409);
            }
            $kind = Input::choice($data['kind'],'操作类型',['change','cancel']);
            $requested = Input::date($data['effective_on']);
            $day = $kind === 'cancel' ? self::month(substr($requested,0,7))[1] : $requested;
            $reason = Input::text($data['reason'],'变更原因',400);
            $timeline = $this->timeline($sub);
            $last = end($timeline);
            if ($requested < $sub['term_start'] || $day < $sub['term_start'] || $day >= $sub['term_end'] || $last['kind'] === 'cancel'
                || (count($timeline)>1 && $day <= $last['effective_on'])) {
                throw new BusinessError('变更日须处于有效服务期，并晚于已有变更。',409);
            }
            $period = substr($day,0,7);
            $before = self::fixedFee($sub,$timeline,$period);
            if ($kind === 'change') {
                $rate = (new SubscriptionPlans($this->db))->onDate($actor,$company,Input::id($data['plan_id'] ?? null,'新套餐'),$day);
                if ($rate['currency'] !== $last['currency'] || $rate['entitlement_rev'] !== ($data['entitlement_rev'] ?? null)) {
                    throw new BusinessError('币种不一致或所选价格版本已变化。',409);
                }
            } else {
                $rate = $last;
            }
            $entry = ['effective_on'=>$day,'kind'=>$kind]+$rate;
            $after = self::fixedFee($sub,[...$timeline,$entry],$period);
            $version = $sub['version']+1;
            $result = ['subscription_id'=>$sub['id'],'amendment_rev'=>$version,'effective_on'=>$day,'kind'=>$kind,'currency'=>$rate['currency'],
                'before_minor'=>$before,'after_minor'=>$after,'proration_minor'=>$after-$before];
            if (!$preview) {
                $result['amendment_id'] = $this->db->insert('INSERT INTO subscription_amendments (company_id,subscription_id,revision,effective_on,kind,plan_id,
                    entitlement_rev,before_minor,after_minor,proration_minor,reason,actor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$company,$sub['id'],$version,$day,$kind,$rate['plan_id'],$rate['entitlement_rev'],$before,$after,$after-$before,$reason,$actor->userId]);
                $this->db->run('UPDATE subscriptions SET version=? WHERE company_id=? AND id=?',[$version,$company,$sub['id']]);
                (new Audit($this->db))->record($actor,$company,'subscription',$sub['id'],$version,'subscription.amend',$result+['reason'=>$reason]);
            }
            return $result;
        });
    }
}
