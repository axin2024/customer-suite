<?php

declare(strict_types=1);

namespace CustomerSuite;

final class BillingReview
{
    public function __construct(private readonly Database $db)
    {
    }

    private function statement(Access $actor,int $company,int $id,int $version): array
    {
        $s = $this->db->row('SELECT * FROM subscription_statements WHERE company_id=? AND id=?',[$company,$id])
            ?? throw new BusinessError('未找到账单。',404);
        (new Subscriptions($this->db))->subject($company,$s['subscription_id'],true);
        (new Subscriptions($this->db))->readable($actor,$company,$s['subscription_id']);
        $s = $this->db->row('SELECT * FROM subscription_statements WHERE company_id=? AND id=? FOR UPDATE',[$company,$id]);
        if ($s['revision'] !== $version) {
            throw new BusinessError('账单已更新，请重新打开。',409);
        }
        return $s;
    }

    public function raise(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'manager','service');
        $s = $this->statement($actor,$company,$data['id'],$data['version']);
        $line = $this->db->row("SELECT usage_id,quantity,amount_minor FROM subscription_statement_lines WHERE company_id=? AND statement_id=? AND id=? AND kind='overage'",
            [$company,$s['id'],$data['line_id']]) ?? throw new BusinessError('未找到可申诉的用量行。',404);
        $units = Input::integer($data['units'],'异议用量',1,$line['quantity']);
        $reason = Input::text($data['reason'],'异议原因',400);
        $amount = intdiv($line['amount_minor'],$line['quantity'])*$units;
        $prior = $this->db->row('SELECT id,units,reason FROM subscription_disputes WHERE company_id=? AND statement_id=? AND usage_id=?',[$company,$s['id'],$line['usage_id']]);
        if ($prior) {
            if ($prior['units'] !== $units || $prior['reason'] !== $reason) {
                throw new BusinessError('该用量已有争议记录。',409);
            }
            $id = $prior['id'];
        } else {
            $id = $this->db->insert('INSERT INTO subscription_disputes (company_id,statement_id,usage_id,units,disputed_minor,reason,raised_by) VALUES (?,?,?,?,?,?,?)',
                [$company,$s['id'],$line['usage_id'],$units,$amount,$reason,$actor->userId]);
            (new Audit($this->db))->record($actor,$company,'dispute',$id,1,'dispute.raise',['usage_id'=>$line['usage_id'],'statement_id'=>$s['id'],'reason'=>$reason]);
        }
        return ['subscription_id'=>$s['subscription_id'],'statement_id'=>$s['id'],'dispute_id'=>$id,'disputed_minor'=>$amount];
    }

    public function decide(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'manager','finance');
        $s = $this->statement($actor,$company,$data['id'],$data['version']);
        $d = $this->db->row('SELECT * FROM subscription_disputes WHERE company_id=? AND statement_id=? AND id=? FOR UPDATE',[$company,$s['id'],$data['dispute_id']])
            ?? throw new BusinessError('未找到当前账单的争议。',404);
        if ($d['revision'] !== $data['dispute_rev'] || !in_array($d['state'],['open','held'],true)) {
            throw new BusinessError('争议已处理或修订号不符。',409);
        }
        $decision = Input::choice($data['decision'],'财务决定',['held','credited','denied']);
        $reason = Input::text($data['reason'],'决定依据',400);
        $credit = null;
        if ($decision === 'credited') {
            $result = (new SubscriptionBilling($this->db))->credit($actor,$company,['id'=>$s['subscription_id'],'usage_id'=>$d['usage_id'],'units'=>$d['units'],
                'reason'=>$reason,'evidence'=>'DISPUTE-'.$d['id']]);
            $credit = $result['credit_id'];
        }
        $this->db->run('UPDATE subscription_disputes SET state=?,revision=revision+1,decided_by=?,decision_reason=?,credit_id=? WHERE company_id=? AND id=?',
            [$decision,$actor->userId,$reason,$credit,$company,$d['id']]);
        $result = ['subscription_id'=>$s['subscription_id'],'statement_id'=>$s['id'],'dispute_id'=>$d['id'],'state'=>$decision,
            'dispute_rev'=>$d['revision']+1,'usage_id'=>$d['usage_id'],'credit_id'=>$credit];
        (new Audit($this->db))->record($actor,$company,'dispute',$d['id'],$d['revision']+1,'dispute.decide',$result+['reason'=>$reason]);
        return $result;
    }

    public function totals(int $company,array $s): array
    {
        $payments = $this->db->rows('SELECT id,receipt_no,received_on,amount_minor FROM statement_payments WHERE company_id=? AND statement_id=? ORDER BY id',[$company,$s['id']]);
        $disputes = $this->db->rows('SELECT id,usage_id,units,disputed_minor,reason,state,revision,raised_by,decided_by,decision_reason,credit_id FROM subscription_disputes
            WHERE company_id=? AND statement_id=? ORDER BY id',[$company,$s['id']]);
        $held = 0;
        foreach ($disputes as $d) {
            if (in_array($d['state'],['open','held'],true)) {
                $held += $d['disputed_minor'];
            }
        }
        $paid = array_sum(array_column($payments,'amount_minor'));
        return ['disputes'=>$disputes,'payments'=>$payments,'disputed_minor'=>$held,'paid_minor'=>$paid,'collectible_minor'=>max(0,$s['total_minor']-$held-$paid)];
    }

    public function payment(Access $actor,int $company,array $data): array
    {
        $actor->allow($company,'manager','finance');
        $s = $this->statement($actor,$company,$data['id'],$data['version']);
        if ($s['state'] !== 'locked') {
            throw new BusinessError('仅复核锁定的账单可以登记付款。',409);
        }
        $ref = Input::key($data['receipt_no'],'外部付款回执',128);
        $day = Input::date($data['received_on']);
        $minor = Money::minor($data['amount'],'到账金额',$s['currency']);
        $prior = $this->db->row('SELECT id,statement_id,received_on,amount_minor FROM statement_payments WHERE company_id=? AND receipt_no=?',[$company,$ref]);
        if ($prior) {
            if ([$prior['statement_id'],$prior['received_on'],$prior['amount_minor']] !== [$s['id'],$day,$minor]) {
                throw new BusinessError('此付款回执已用于其他账务。',409);
            }
            $id = $prior['id'];
        } else {
            if ($minor <= 0 || $minor > $this->totals($company,$s)['collectible_minor']) {
                throw new BusinessError('到账金额超过当前可收余额或为零。',409);
            }
            $id = $this->db->insert('INSERT INTO statement_payments (company_id,statement_id,receipt_no,received_on,amount_minor,actor_id) VALUES (?,?,?,?,?,?)',
                [$company,$s['id'],$ref,$day,$minor,$actor->userId]);
            (new Audit($this->db))->record($actor,$company,'payment',$id,1,'billing.payment',['statement_id'=>$s['id'],'receipt_no'=>$ref,'amount_minor'=>$minor]);
        }
        return ['subscription_id'=>$s['subscription_id'],'statement_id'=>$s['id'],'payment_id'=>$id,'amount_minor'=>$minor];
    }

    public function importPayments(Access $actor,int $company,string $csv): array
    {
        $actor->allow($company,'manager','finance');
        if (strlen($csv)>262144 || str_contains($csv,"\0") || !mb_check_encoding($csv,'UTF-8')) {
            throw new BusinessError('请提供 256 KiB 以内的 UTF-8 付款回执 CSV。');
        }
        $stream = fopen('php://temp','w+b');
        try {
            fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));
            rewind($stream);
            $columns = ['statement_id','revision','receipt_no','received_on','amount'];
            if (fgetcsv($stream,0,',','"','') !== $columns) {
                throw new BusinessError('回执表头须为 '.implode(',',$columns).'。');
            }
            $items = [];
            while (($row=fgetcsv($stream,0,',','"','')) !== false) {
                if ($row===[null]) { continue; }
                $number = count($items)+2;
                if ($number>501 || count($row)!==5) {
                    throw new BusinessError('第 '.$number.' 行列数有误或超过 500 条回执。',422,['row'=>$number]);
                }
                try {
                    $items[] = $this->payment($actor,$company,['id'=>Input::id($row[0]),'version'=>Input::id($row[1]),
                        'receipt_no'=>$row[2],'received_on'=>$row[3],'amount'=>$row[4]]);
                } catch (BusinessError $error) {
                    throw new BusinessError('第 '.$number.' 行：'.$error->getMessage(),$error->status,['row'=>$number]);
                }
            }
            if (!$items) { throw new BusinessError('回执文件没有明细。'); }
            return ['count'=>count($items),'payments'=>$items];
        } finally {
            fclose($stream);
        }
    }
}
