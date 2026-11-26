<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Contacts;
use CustomerSuite\Database;
use CustomerSuite\Identity;
use CustomerSuite\Operations;

function signedSubscriptionFixture(Database $db, Access $admin, int $company, string $tag, int $seatLimit = 10): array
{
    $ops = new Operations($db);
    $write = fn(string $action,array $data): array => $ops->execute($admin,$company,$action,bin2hex(random_bytes(12)),$data)['result'];
    $accounts = new Accounts($db);
    $payer = $accounts->create($admin,$company,['name'=>'集团付款主体 '.$tag])['id'];
    $link = ['parent_company_id'=>$company,'parent_id'=>$payer];
    $branches = [];
    foreach (['EAST','WEST'] as $code) {
        $branch = (new Identity($db))->addCompany($admin,$code.'-'.$tag,$code,$company);
        $branches[] = ['service_company_id'=>$branch,'service_account_id'=>$accounts->create($admin,$branch,['name'=>$code.' 服务客户']+$link)['id']];
    }
    $contact = (new Contacts($db))->save($admin,$company,$payer,['name'=>'签约人','job_title'=>'采购负责人']);
    $opp = ['id'=>$write('opportunity.create',['account_id'=>$payer,'title'=>'订阅服务','currency'=>'CNY','amount'=>'1000','expected_close'=>'2027-03-01'])['id']];
    $write('opportunity.move',$opp+['version'=>1,'stage'=>'qualified','decision_contact_id'=>$contact['id']]);
    $write('opportunity.products',$opp+['version'=>2,'products'=>[['product_code'=>'TEAMS','product_version'=>'1','description'=>'团队席位',
        'quantity'=>$seatLimit,'unit_price'=>'100.00']]]);
    $write('opportunity.move',$opp+['version'=>3,'stage'=>'proposal']);
    $q = ['id'=>$write('quote.prepare',['opportunity_id'=>$opp['id'],'opportunity_version'=>4,'discount_bps'=>0,'expires_on'=>'2027-03-31','terms'=>'按月计量'])['id']];
    $write('quote.submit',$q+['version'=>1]);
    $write('agreement.sign',$q+['version'=>2,'scope'=>'single','allocations'=>[['company_id'=>$company,'account_id'=>$payer,
        'amount'=>(string)($seatLimit*100),'reference_no'=>'SUB-'.$tag,'starts_on'=>'2027-03-01','ends_on'=>'2027-12-31','service_level'=>'priority','evidence_ref'=>'SIGNED-'.$tag]]]);
    $agreement = $db->row('SELECT id FROM agreements WHERE company_id=? AND quote_id=?',[$company,$q['id']])['id'];
    $plan = $write('plan.publish',['code'=>'TEAMS','name'=>'协作订阅','previous_revision'=>0,'effective_on'=>'2027-02-01','currency'=>'CNY',
        'monthly_fee'=>'100.00','service_level'=>'priority','metrics'=>[['metric'=>'api_calls','included_units'=>100,'overage_fee'=>'2.00']]]);
    rejects(409,fn()=>$write('pilot.change',['version'=>0,'state'=>'active']),'An unenrolled contract blocks pilot activation');
    $write('pilot.backfill',['limit'=>1]);
    $write('pilot.change',['version'=>0,'state'=>'active']);
    return ['payer'=>$payer,'agreement'=>$agreement,'branches'=>$branches,'open'=>['agreement_id'=>$agreement,'agreement_revision'=>1,
        'line_no'=>1,'plan_id'=>$plan['plan_id'],'entitlement_rev'=>1,'term_start'=>'2027-03-01','term_end'=>'2027-05-01',
        'billing_timezone'=>'Asia/Shanghai','mode'=>'paid','seats'=>6]];
}
