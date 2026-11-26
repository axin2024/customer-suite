<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Identity;
use CustomerSuite\Operations;
use CustomerSuite\Queries;
use CustomerSuite\Receipts;

require __DIR__.'/bootstrap.php';
$tag = bin2hex(random_bytes(5));
$identity = new Identity($db);
$password = 'Test-only-'.bin2hex(random_bytes(16));
$root = $db->row('SELECT id FROM users WHERE is_admin=1 ORDER BY id LIMIT 1');
$admin = new Access($db,$root['id'] ?? $identity->bootstrap('测试管理员',$tag.'@example.test',$password));
$company = $identity->addCompany($admin,'PLAN-'.$tag,'订阅产品公司');
$other = $identity->addCompany($admin,'OTHER-'.$tag,'其他产品公司');
$managerId = $identity->addUser($admin,'产品经理',$tag.'.manager@example.test',$password);
$identity->membership($admin,$company,$managerId,'manager',true);
$manager = new Access($db,$managerId);
$ops = new Operations($db);
$query = new Queries($db);
$data = ['code'=>'TEAMS','name'=>'协作版','previous_revision'=>0,'effective_on'=>'2027-03-01','currency'=>'CNY',
    'monthly_fee'=>'99.00','service_level'=>'priority','metrics'=>[['metric'=>'api_calls','included_units'=>100,'overage_fee'=>'2.00']]];
$result = $ops->execute($manager,$company,'plan.publish','v1',$data);
$id = $result['result']['plan_id'];
same(1,$result['result']['entitlement_rev'],'Initial version');
same(true,$ops->execute($manager,$company,'plan.publish','v1',$data)['replayed'],'Replay preserves version');
$new = $data;
$new['previous_revision'] = 1;
$new['effective_on'] = '2027-04-01';
$new['monthly_fee'] = '129.00';
$new['metrics'][0]['overage_fee'] = '3.00';
same(2,$ops->execute($manager,$company,'plan.publish','v2',$new)['result']['entitlement_rev'],'Future rate published');
$read = fn(string $day): array => $query->read($manager,$company,'subscription-plan',['id'=>$id,'date'=>$day]);
$march = $read('2027-03-31');
same([9900,100,200],[$march['monthly_minor'],$march['metrics'][0]['included_units'],$march['metrics'][0]['overage_minor']],'Old price and included quota preserved');
same(12900,$read('2027-04-01')['monthly_minor'],'New price starts on effective date');
rejects(404,fn()=>$read('2027-02-28'),'Before first publication');
rejects(409,fn()=>$ops->execute($manager,$company,'plan.publish','stale',$new),'Reject stale revision');
rejects(404,fn()=>$query->read($admin,$other,'subscription-plan',['id'=>$id,'date'=>'2027-04-01']),'Isolate price catalog by company');
$identity->membership($admin,$company,$managerId,'service',true);
$identity->membership($admin,$company,$managerId,'manager',false);
rejects(403,fn()=>(new Receipts($db))->read($manager,$company,'v1'),'Revoked editor cannot recover a publication receipt');
same(2,$read('2027-04-01')['entitlement_rev'],'Service desk can explain current price');
done('Subscription plan history');
