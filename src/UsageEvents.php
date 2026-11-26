<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class UsageEvents
{
    public function __construct(private readonly Database $db)
    {
    }

    public function ingest(Access $actor, int $company, int $id, string $key, array $input): array
    {
        $key = Input::key($key,'请求标识',128);
        $fields = ['source'=>fn($v)=>Input::key($v,'来源',64),'event_id'=>fn($v)=>Input::key($v,'事件号',128),
            'metric'=>fn($v)=>Input::key($v,'计量项',64),'occurred_at'=>fn($v)=>Input::instant($v),
            'units'=>fn($v)=>Input::integer($v,'用量',1,1000000000)];
        $data = [];
        foreach ($fields as $field=>$validate) {
            try {
                $data[$field] = $validate($input[$field] ?? null);
            } catch (BusinessError $error) {
                throw new BusinessError($error->getMessage(),422,['field'=>$field]);
            }
        }
        $unknown = array_diff_key($input,$fields);
        if ($unknown) {
            throw new BusinessError('包含未定义的计量字段。',422,['field'=>array_key_first($unknown)]);
        }
        $result = $this->db->transaction(function () use ($actor,$company,$id,$key,$data): array {
            $actor->allow($company,'manager','meter');
            $sub = (new Subscriptions($this->db))->subject($company,$id,true);
            $day = (new DateTimeImmutable($data['occurred_at'],new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($sub['billing_timezone']))->format('Y-m-d');
            $period = substr($day,0,7);
            $digest = Input::digest(['subscription_id'=>$id]+$data);
            $keys = [$company,$actor->userId,$key];
            $this->db->run('INSERT INTO usage_request_keys (company_id,actor_id,request_key,digest) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE digest=digest',[...$keys,$digest]);
            $request = $this->db->row('SELECT digest FROM usage_request_keys WHERE company_id=? AND actor_id=? AND request_key=?',$keys);
            $prior = $this->db->row('SELECT id,usage_id,disposition FROM usage_attempts WHERE company_id=? AND actor_id=? AND request_key=? AND digest=?',
                [...$keys,$digest]);
            if ($prior) {
                return $this->receipt($prior,$period);
            }
            $businessKey = [$company,$data['source'],$data['event_id']];
            $event = $this->db->row('SELECT id,digest FROM subscription_usage WHERE company_id=? AND source=? AND event_id=?',$businessKey);
            if (!$event && $request['digest'] === $digest) {
                (new SubscriptionPilot($this->db))->accepting($company);
                $rate = SubscriptionTerms::onDay((new SubscriptionTerms($this->db))->timeline($sub),$day);
                if ($day < $sub['term_start'] || $day >= $sub['term_end'] || $rate['kind'] === 'cancel') {
                    throw new BusinessError('按订阅时区折算后，事件不在服务期内。',422,['field'=>'occurred_at']);
                }
                if (!$this->db->row('SELECT metric FROM subscription_rate_metrics WHERE company_id=? AND plan_id=? AND revision=? AND metric=?',
                    [$company,$rate['plan_id'],$rate['entitlement_rev'],$data['metric']])) {
                    throw new BusinessError('签约套餐没有此计量项。',422,['field'=>'metric']);
                }
                $this->db->run('INSERT INTO subscription_usage (company_id,source,event_id,subscription_id,metric,units,occurred_at,local_date,billing_period,digest)
                    VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                    [...$businessKey,$id,$data['metric'],$data['units'],$data['occurred_at'],$day,$period,$digest]);
                $event = $this->db->row('SELECT id,digest FROM subscription_usage WHERE company_id=? AND source=? AND event_id=?',$businessKey);
                $disposition = $event['digest'] === $digest ? 'accepted' : 'conflict';
            } else {
                $disposition = $request['digest'] === $digest && $event && $event['digest'] === $digest ? 'duplicate' : 'conflict';
            }
            $attempt = $this->db->insert('INSERT INTO usage_attempts (company_id,actor_id,request_key,subscription_id,usage_id,digest,payload,disposition)
                VALUES (?,?,?,?,?,?,?,?)',[...$keys,$id,$event['id'] ?? null,$digest,Input::json($data),$disposition]);
            return $this->receipt(['id'=>$attempt,'usage_id'=>$event['id'] ?? null,'disposition'=>$disposition],$period);
        });
        if ($result['disposition'] === 'conflict') {
            throw new BusinessError('计量事件存在差异，已提交人工核查。',409,$result);
        }
        return $result;
    }

    private function receipt(array $row, string $period): array
    {
        return ['usage_id'=>$row['usage_id'],'billing_period'=>$period,'disposition'=>$row['disposition'],'attempt_id'=>$row['id']];
    }

    public function history(Access $actor,int $company,int $subscription,string $disposition,int $before): array
    {
        $actor->allow($company,'manager','meter','finance');
        (new Subscriptions($this->db))->subject($company,$subscription);
        $disposition = Input::choice($disposition,'受理结果',['accepted','duplicate','conflict']);
        return ['items'=>$this->db->rows('SELECT a.id,a.usage_id,a.request_key,a.actor_id,a.disposition,a.payload,a.created_at,r.reviewer_id,r.reason
            FROM usage_attempts a LEFT JOIN usage_conflict_reviews r ON r.company_id=a.company_id AND r.attempt_id=a.id
            WHERE a.company_id=? AND a.subscription_id=? AND a.disposition=? AND a.id<? ORDER BY a.id DESC LIMIT 50',[$company,$subscription,$disposition,$before])];
    }

    public function review(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'manager','meter');
        $attempt = $this->db->row("SELECT id,subscription_id,usage_id FROM usage_attempts WHERE company_id=? AND subscription_id=? AND id=? AND disposition='conflict' FOR UPDATE",
            [$company,$data['id'],$data['attempt_id']]) ?? throw new BusinessError('未找到此差异处理号。',404);
        $reason = Input::text($data['reason'],'核查结论',400);
        $prior = $this->db->row('SELECT reviewer_id,reason FROM usage_conflict_reviews WHERE company_id=? AND attempt_id=?',[$company,$attempt['id']]);
        if ($prior === null) {
            $this->db->run('INSERT INTO usage_conflict_reviews (company_id,attempt_id,reviewer_id,reason) VALUES (?,?,?,?)',[$company,$attempt['id'],$actor->userId,$reason]);
            (new Audit($this->db))->record($actor,$company,'usage_attempt',$attempt['id'],1,'usage.review',['reason'=>$reason]);
        } elseif ($prior['reason'] !== $reason) {
            throw new BusinessError('差异已有核查结论。',409);
        }
        return $attempt+['reason'=>$reason,'reviewer_id'=>$prior['reviewer_id'] ?? $actor->userId];
    }
}
