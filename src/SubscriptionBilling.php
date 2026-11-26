<?php

declare(strict_types=1);

namespace CustomerSuite;

final class SubscriptionBilling
{
    public function __construct(private readonly Database $db)
    {
    }

    private function baseLines(array $sub,array $terms,string $period): array
    {
        [$first,$stop] = SubscriptionTerms::month($period);
        $fee = SubscriptionTerms::fixedFee($sub,$terms,$period);
        $rate = SubscriptionTerms::onDay($terms,$first);
        $segments = [];
        foreach ($terms as $i=>$term) {
            $from = max($first,$term['effective_on'],$sub['paid_from'] ?? $stop);
            $end = min($stop,$terms[$i+1]['effective_on'] ?? $stop,$sub['term_end']);
            if ($from < $end && $term['kind'] !== 'cancel') {
                $segments[] = ['from'=>$from,'until'=>$end,'plan_id'=>$term['plan_id'],'rate_rev'=>$term['entitlement_rev'],'monthly_minor'=>$term['monthly_minor']];
            }
        }
        $lines = ['fixed'=>['origin_period'=>$period,'origin_key'=>'fixed','kind'=>'fixed','usage_id'=>null,'plan_id'=>$rate['plan_id'],
            'rate_rev'=>$rate['entitlement_rev'],'quantity'=>1,'amount_minor'=>$fee,'detail'=>['segments'=>$segments]]];
        if ($sub['paid_from'] === null) {
            return $lines;
        }
        $events = $this->db->rows('SELECT u.id,u.metric,u.units,u.local_date,COALESCE((SELECT SUM(c.units) FROM subscription_usage_credits c
            WHERE c.company_id=u.company_id AND c.usage_id=u.id),0) credited FROM subscription_usage u
            WHERE u.company_id=? AND u.subscription_id=? AND u.billing_period=? AND u.local_date>=? ORDER BY u.occurred_at,u.id',
            [$sub['company_id'],$sub['id'],$period,$sub['paid_from']]);
        $rates = [];
        $used = [];
        foreach ($events as $event) {
            $price = SubscriptionTerms::onDay($terms,$event['local_date']);
            if ($price['kind'] === 'cancel') {
                continue;
            }
            $key = $price['plan_id'].':'.$price['entitlement_rev'];
            if (!isset($rates[$key])) {
                $rates[$key] = array_column($this->db->rows('SELECT metric,included_units,overage_minor FROM subscription_rate_metrics
                    WHERE company_id=? AND plan_id=? AND revision=?',[$sub['company_id'],$price['plan_id'],$price['entitlement_rev']]),null,'metric');
            }
            $metric = $event['metric'];
            $policy = $rates[$key][$metric] ?? throw new BusinessError('变更后缺少历史用量的计价规则。',409,['usage_id'=>$event['id']]);
            $before = $used[$metric] ?? 0;
            $units = $event['units']-(int)$event['credited'];
            $used[$metric] = $before+$units;
            $billable = max(0,$used[$metric]-$policy['included_units'])-max(0,$before-$policy['included_units']);
            if ($billable === 0) {
                continue;
            }
            if ($policy['overage_minor'] > intdiv(Money::MAX_MINOR,$billable)) {
                throw new BusinessError('单笔超量费超出金额上限。',422,['usage_id'=>$event['id']]);
            }
            $ref = 'usage:'.$event['id'];
            $lines[$ref] = ['origin_period'=>$period,'origin_key'=>$ref,'kind'=>'overage','usage_id'=>$event['id'],'plan_id'=>$price['plan_id'],
                'rate_rev'=>$price['entitlement_rev'],'quantity'=>$billable,'amount_minor'=>$billable*$policy['overage_minor'],
                'detail'=>['metric'=>$metric,'units'=>$units,'free_units'=>$policy['included_units'],'unit_minor'=>$policy['overage_minor'],'prior_usage'=>$before]];
        }
        return $lines;
    }

    public function draft(Access $actor,int $company,int $id,string $period): array
    {
        SubscriptionTerms::month($period);
        $actor->allow($company,'manager','finance');
        return $this->db->transaction(function () use ($actor,$company,$id,$period): array {
            $sub = (new Subscriptions($this->db))->subject($company,$id,true);
            if ($period < substr($sub['term_start'],0,7)) {
                throw new BusinessError('账期早于订阅起始月。');
            }
            $terms = (new SubscriptionTerms($this->db))->timeline($sub);
            $current = $this->db->row('SELECT * FROM subscription_statements WHERE company_id=? AND subscription_id=? AND billing_period=?',[$company,$id,$period]);
            if (($current['state'] ?? null) === 'locked') {
                return $this->receipt($current);
            }
            $previous = $this->db->rows("SELECT billing_period FROM subscription_statements WHERE company_id=? AND subscription_id=? AND state='locked'
                ORDER BY billing_period",[$company,$id]);
            if ($previous && end($previous)['billing_period'] > $period) {
                throw new BusinessError('后续账期已锁定。',409);
            }
            $rows = array_values($this->baseLines($sub,$terms,$period));
            // Compare recalculated historical charges with every posted original and adjustment for that source period.
            $posted = $this->db->rows("SELECT l.origin_period,l.origin_key,SUM(l.amount_minor) amount_minor,SUM(l.quantity) quantity,
                    MAX(l.usage_id) usage_id,MIN(l.id) original_line,MIN(l.plan_id) plan_id,MIN(l.rate_rev) rate_rev
                FROM subscription_statement_lines l JOIN subscription_statements s ON s.company_id=l.company_id AND s.id=l.statement_id
                WHERE s.company_id=? AND s.subscription_id=? AND s.state='locked' GROUP BY l.origin_period,l.origin_key",[$company,$id]);
            $byPeriod = [];
            foreach ($posted as $line) {
                $byPeriod[$line['origin_period']][$line['origin_key']] = $line;
            }
            foreach (array_column($previous,'billing_period') as $oldPeriod) {
                $expected = $this->baseLines($sub,$terms,$oldPeriod);
                $billed = $byPeriod[$oldPeriod] ?? [];
                foreach (array_unique([...array_keys($expected),...array_keys($billed)]) as $ref) {
                    $line = $expected[$ref] ?? null;
                    $prior = $billed[$ref] ?? [];
                    $difference = ($line['amount_minor'] ?? 0)-(int)($prior['amount_minor'] ?? 0);
                    if (!$difference) {
                        continue;
                    }
                    $rows[] = ['origin_period'=>$oldPeriod,'origin_key'=>$ref,'kind'=>'adjustment','usage_id'=>$line['usage_id'] ?? $prior['usage_id'] ?? null,
                        'plan_id'=>$line['plan_id'] ?? $prior['plan_id'],'rate_rev'=>$line['rate_rev'] ?? $prior['rate_rev'],
                        'quantity'=>($line['quantity'] ?? 0)-(int)($prior['quantity'] ?? 0),'amount_minor'=>$difference,
                        'detail'=>['original_line'=>$prior['original_line'] ?? null,'previous_minor'=>(int)($prior['amount_minor'] ?? 0),
                            'corrected_minor'=>$line['amount_minor'] ?? 0]];
                }
            }
            usort($rows,static fn(array $a,array $b): int => [$a['origin_period'],$a['origin_key']] <=> [$b['origin_period'],$b['origin_key']]);
            $digest = Input::digest($rows);
            if ($current && $current['digest'] === $digest) {
                return $this->receipt($current);
            }
            $total = 0;
            foreach ($rows as $line) {
                $total += $line['amount_minor'];
                if (abs($total) > Money::MAX_MINOR) {
                    throw new BusinessError('账单金额超过结算上限。');
                }
            }
            if ($current) {
                $sid = $current['id'];
                $this->db->run('UPDATE subscription_statements SET revision=revision+1,digest=?,total_minor=?,prepared_by=? WHERE company_id=? AND id=?',
                    [$digest,$total,$actor->userId,$company,$sid]);
                $this->db->run('DELETE FROM subscription_statement_lines WHERE company_id=? AND statement_id=?',[$company,$sid]);
            } else {
                $sid = $this->db->insert("INSERT INTO subscription_statements (company_id,subscription_id,billing_period,currency,revision,state,total_minor,digest,prepared_by)
                    VALUES (?,?,?,?,1,'draft',?,?,?)",[$company,$id,$period,$terms[0]['currency'],$total,$digest,$actor->userId]);
            }
            $values = [];
            foreach ($rows as $line) {
                $values[] = [$company,$sid,$line['origin_period'],$line['origin_key'],$line['kind'],$line['usage_id'],$line['plan_id'],$line['rate_rev'],
                    $line['quantity'],$line['amount_minor'],Input::json($line['detail'])];
            }
            $this->db->insertRows('INSERT INTO subscription_statement_lines (company_id,statement_id,origin_period,origin_key,kind,usage_id,plan_id,rate_rev,quantity,amount_minor,detail)',$values);
            $result = $this->receipt($this->statement($company,$sid));
            (new Audit($this->db))->record($actor,$company,'statement',$sid,$result['statement_rev'],'billing.draft',$result);
            return $result;
        });
    }

    public function batch(Access $actor,int $company,string $period,int $after): array
    {
        $actor->allow($company,'manager','finance');
        [, $end] = SubscriptionTerms::month($period);
        $subs = $this->db->rows('SELECT s.id FROM subscriptions s WHERE s.company_id=? AND s.term_start<? AND s.id>?
            ORDER BY s.id LIMIT 51',[$company,$end,$after]);
        $items = [];
        foreach (array_slice($subs,0,50) as $sub) {
            $items[] = $this->draft($actor,$company,$sub['id'],$period);
        }
        return ['billing_period'=>$period,'items'=>$items,'next_after'=>count($subs)>50 ? $items[49]['subscription_id'] : null];
    }

    public function lock(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'manager','finance');
        $s = $this->statement($company,$data['id']);
        return $this->db->transaction(function () use ($actor,$company,$data,$s): array {
            $actual = $this->draft($actor,$company,$s['subscription_id'],$s['billing_period']);
            if ($actual['statement_rev'] !== $data['version'] || $actual['digest'] !== $data['digest']) {
                throw new BusinessError('账单与预览不一致，请重新生成并核对。',409);
            }
            if ($actual['state'] === 'draft') {
                $this->db->run("UPDATE subscription_statements SET state='locked',locked_by=?,locked_at=UTC_TIMESTAMP(6) WHERE company_id=? AND id=?",
                    [$actor->userId,$company,$s['id']]);
                (new Audit($this->db))->record($actor,$company,'statement',$s['id'],$actual['statement_rev'],'billing.lock',$actual);
            }
            return $this->receipt($this->statement($company,$s['id']));
        });
    }

    public function credit(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'finance','manager');
        $sub = (new Subscriptions($this->db))->subject($company,$data['id'],true);
        $event = $this->db->row('SELECT id,units FROM subscription_usage WHERE company_id=? AND subscription_id=? AND id=?',[$company,$sub['id'],$data['usage_id']])
            ?? throw new BusinessError('该订阅没有此用量记录。',404);
        $units = Input::integer($data['units'],'冲减用量',1,1000000000);
        $ref = Input::key($data['evidence'],'调整依据',128);
        $reason = Input::text($data['reason'],'调整原因',400);
        $prior = $this->db->row('SELECT id,usage_id,units,reason FROM subscription_usage_credits WHERE company_id=? AND evidence=?',[$company,$ref]);
        if ($prior) {
            if ([$prior['usage_id'],$prior['units'],$prior['reason']] !== [$event['id'],$units,$reason]) {
                throw new BusinessError('调整依据已用于其他内容。',409);
            }
            $credit = $prior['id'];
        } else {
            $used = $this->db->row('SELECT COALESCE(SUM(units),0) n FROM subscription_usage_credits WHERE company_id=? AND usage_id=?',[$company,$event['id']]);
            if ($units+(int)$used['n'] > $event['units']) {
                throw new BusinessError('累计冲减超过原始用量。',409);
            }
            $credit = $this->db->insert('INSERT INTO subscription_usage_credits (company_id,usage_id,units,evidence,reason,actor_id) VALUES (?,?,?,?,?,?)',
                [$company,$event['id'],$units,$ref,$reason,$actor->userId]);
            (new Audit($this->db))->record($actor,$company,'usage',$event['id'],1,'billing.credit',['credit_id'=>$credit,'units'=>$units,'evidence'=>$ref]);
        }
        return ['subscription_id'=>$sub['id'],'usage_id'=>$event['id'],'credit_id'=>$credit,'units'=>$units];
    }

    private function statement(int $company,int $id): array
    {
        return $this->db->row('SELECT * FROM subscription_statements WHERE company_id=? AND id=?',[$company,$id]) ?? throw new BusinessError('未找到账单。',404);
    }

    private function receipt(array $s): array
    {
        return ['subscription_id'=>$s['subscription_id'],'statement_id'=>$s['id'],'statement_rev'=>$s['revision'],'state'=>$s['state'],
            'billing_period'=>$s['billing_period'],'currency'=>$s['currency'],'total_minor'=>$s['total_minor'],'digest'=>$s['digest']];
    }

    public function detail(Access $actor,int $company,int $id): array
    {
        $actor->company($company);
        $s = $this->statement($company,$id);
        if (!(new Subscriptions($this->db))->canReadBilling($actor,$company,$s['subscription_id'])) {
            throw new BusinessError('未找到此账单。',404);
        }
        if ($s['state'] === 'draft') {
            $actor->allow($company,'manager','finance','service');
        }
        $lines = $this->db->rows('SELECT id,origin_period,origin_key,kind,usage_id,plan_id,rate_rev,quantity,amount_minor,detail
            FROM subscription_statement_lines WHERE company_id=? AND statement_id=? ORDER BY origin_period,origin_key',[$company,$s['id']]);
        $credits = $this->db->rows('SELECT DISTINCT c.id,c.usage_id,c.evidence,c.units,c.reason FROM subscription_usage_credits c JOIN subscription_statement_lines l
            ON l.company_id=c.company_id AND l.usage_id=c.usage_id WHERE l.company_id=? AND l.statement_id=? ORDER BY c.id',[$company,$s['id']]);
        return $this->receipt($s)+['lines'=>$lines,'credits'=>$credits]+(new BillingReview($this->db))->totals($company,$s);
    }
}
