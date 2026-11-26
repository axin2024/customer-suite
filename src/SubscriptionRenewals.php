<?php
declare(strict_types=1);

namespace CustomerSuite;

final class SubscriptionRenewals
{
    public function __construct(private readonly Database $db)
    {
    }

    public function scan(Access $actor,int $company,string $month): array
    {
        $actor->allow($company,'manager');
        (new SubscriptionPilot($this->db))->accepting($company);
        [$first,$end] = SubscriptionTerms::month($month);
        $rows = $this->db->rows("SELECT DISTINCT s.agreement_id,s.term_end FROM subscriptions s
            JOIN agreements a ON a.company_id=s.company_id AND a.id=s.agreement_id AND a.state<>'ended'
            JOIN success_assignments w ON w.company_id=s.company_id AND w.agreement_id=s.agreement_id
            JOIN users u ON u.id=w.owner_id AND u.active=1
            WHERE s.company_id=? AND s.term_end> ? AND s.term_end<=? AND (u.is_admin=1 OR EXISTS
            (SELECT 1 FROM memberships m WHERE m.company_id=s.company_id AND m.user_id=u.id AND m.role='success'))
            AND NOT EXISTS (SELECT 1 FROM subscription_renewals r WHERE r.company_id=s.company_id AND r.agreement_id=s.agreement_id AND r.term_end=s.term_end)
            ORDER BY s.agreement_id,s.term_end LIMIT 51",[$company,$first,$end]);
        $items = [];
        foreach (array_slice($rows,0,50) as $candidate) {
            $id = $candidate['agreement_id'];
            [$contract,,$owner] = (new SuccessContracts($this->db))->lock($actor,$company,$id);
            $term = $candidate['term_end'];
            if ($contract['state']==='ended' || $this->db->row('SELECT id FROM subscription_renewals WHERE company_id=? AND agreement_id=? AND term_end=?',[$company,$id,$term])) {
                continue;
            }
            $subscriptions = $this->db->rows('SELECT * FROM subscriptions WHERE company_id=? AND agreement_id=? AND term_end=? ORDER BY id FOR UPDATE',[$company,$id,$term]);
            $finance = 0;
            $exposure = [];
            $day = (new \DateTimeImmutable($term))->modify('-1 day')->format('Y-m-d');
            foreach ($subscriptions as $sub) {
                $rate = SubscriptionTerms::onDay((new SubscriptionTerms($this->db))->timeline($sub),$day);
                $monthly = $rate['kind'] !== 'cancel' && $sub['paid_from'] !== null && $sub['paid_from'] <= $day ? $rate['monthly_minor'] : 0;
                $finance += 12*$monthly;
                if ($finance > Money::MAX_MINOR) {
                    throw new BusinessError('续约预测超出金额上限。');
                }
                $exposure[] = ['subscription_id'=>$sub['id'],'service_account_id'=>$sub['service_account_id'],'monthly_minor'=>$monthly,'seats'=>$sub['seats']];
            }
            $currency = (new Agreements($this->db))->revision($company,$id,$contract['active_revision'])['currency'];
            $renewal = $this->db->insert('INSERT INTO subscription_renewals (company_id,agreement_id,agreement_revision,payer_id,term_end,currency,forecast_minor,exposure,owner_id)
                VALUES (?,?,?,?,?,?,?,?,?)',[$company,$id,$contract['active_revision'],$contract['account_id'],$term,$currency,$finance,Input::json($exposure),$owner]);
            $item = ['renewal_id'=>$renewal,'agreement_id'=>$id,'forecast_minor'=>$finance,'currency'=>$currency,'owner_id'=>$owner];
            (new Audit($this->db))->record($actor,$company,'subscription_renewal',$renewal,1,'retention.scan',$item);
            $items[] = $item;
        }
        return ['month'=>$month,'created'=>count($items),'more'=>count($rows)>50,'items'=>$items];
    }

    public function change(Access $actor,int $company,array $data,string $action): array
    {
        $raw = $this->db->row('SELECT agreement_id FROM subscription_renewals WHERE company_id=? AND id=?',[$company,$data['id']])
            ?? throw new BusinessError('未找到续约案件。',404);
        [$agreement] = (new SuccessContracts($this->db))->lock($actor,$company,$raw['agreement_id']);
        $case = $this->db->row('SELECT * FROM subscription_renewals WHERE company_id=? AND id=? FOR UPDATE',[$company,$data['id']]);
        Accounts::checkVersion($case,$data['version']);
        if (!in_array($case['state'],['open','working'],true)) {
            throw new BusinessError('续约案件已经结束。',409);
        }
        $reason = Input::text($data['reason'],'保留动作或关闭原因',400);
        if ($action === 'retention.follow') {
            $forecast = Money::minor($data['forecast'],'预计续期金额',$case['currency']);
            $date = Input::date($data['follow_up']);
            $this->db->run("UPDATE subscription_renewals SET state='working',retention_action=?,follow_up=?,forecast_minor=?,version=version+1 WHERE company_id=? AND id=?",
                [$reason,$date,$forecast,$company,$case['id']]);
            $result = ['state'=>'working','forecast_minor'=>$forecast,'follow_up'=>$date];
        } else {
            $outcome = Input::choice($data['outcome'],'续期结果',['lost','renewed']);
            $signed = null;
            $rec = null;
            $amount = 0;
            if ($outcome === 'renewed') {
                $signed = Input::id($data['signed_agreement_id'] ?? null,'已签续期合同');
                $agreements = new Agreements($this->db);
                $next = $agreements->subject($company,$signed,true);
                $rec = $agreements->revision($company,$signed,$next['active_revision']);
                if ($next['account_id'] !== $agreement['account_id'] || $rec['currency'] !== $case['currency'] || $rec['decision'] !== 'signed'
                    || $rec['starts_on'] < $case['term_end'] || $this->db->row('SELECT id FROM subscription_renewals WHERE company_id=? AND signed_agreement_id=? AND signed_revision=?',
                    [$company,$signed,$rec['revision']])) {
                    throw new BusinessError('续期金额须来自同一客户、同一币种且尚未归入其他案件的已签续期合同。',409);
                }
                $amount = $rec['value_minor'];
            }
            $this->db->run('UPDATE subscription_renewals SET state=?,close_reason=?,signed_agreement_id=?,signed_revision=?,signed_minor=?,follow_up=NULL,version=version+1
                WHERE company_id=? AND id=?',[$outcome,$reason,$signed,$rec['revision'] ?? null,$amount,$company,$case['id']]);
            $result = ['state'=>$outcome,'signed_minor'=>$amount,'signed_agreement_id'=>$signed,'forecast_minor'=>$case['forecast_minor']];
        }
        $result += ['renewal_id'=>$case['id'],'agreement_id'=>$agreement['id'],'version'=>$case['version']+1];
        (new Audit($this->db))->record($actor,$company,'subscription_renewal',$case['id'],$result['version'],$action,$result+['reason'=>$reason]);
        return $result;
    }

    public function board(Access $actor,int $company,string $month,int $before): array
    {
        $actor->allow($company,'manager','success');
        [$first,$end] = SubscriptionTerms::month($month);
        $where = ' FROM subscription_renewals r JOIN success_assignments w ON w.company_id=r.company_id AND w.agreement_id=r.agreement_id
            WHERE r.company_id=? AND r.term_end>? AND r.term_end<=? AND (? OR w.owner_id=?)';
        $params = [$company,$first,$end,(int)$actor->has($company,'manager'),$actor->userId];
        $rows = $this->db->rows('SELECT r.*,w.owner_id AS owner_id'.$where.' AND r.id<? ORDER BY r.id DESC LIMIT 51',[...$params,$before]);
        $summary = $this->db->rows("SELECT currency,COUNT(*) cases,SUM(state='renewed') renewed,SUM(state='lost') lost,
            SUM(IF(state IN ('open','working'),forecast_minor,0)) forecast_minor,SUM(signed_minor) signed_minor".$where.' GROUP BY currency ORDER BY currency',$params);
        foreach ($summary as &$s) {
            $closed = (int)$s['lost']+(int)$s['renewed'];
            $s['win_rate_bps'] = $closed ? intdiv((int)$s['renewed']*10000,$closed) : null;
        }
        return ['month'=>$month,'items'=>array_slice($rows,0,50),'summary'=>$summary,'next_before'=>count($rows)>50 ? $rows[49]['id'] : null];
    }
}
