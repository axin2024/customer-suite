<?php

declare(strict_types=1);

use CustomerSuite\Operations;

require __DIR__.'/SubscriptionFixture.php';
$db->run("SET timestamp = UNIX_TIMESTAMP('2027-03-01 00:00:00')");
$billing = $identity->addCompany($admin,'BILL-'.$tag,'计量运营');
$fixture = signedSubscriptionFixture($db,$admin,$billing,'web'.$tag);
$billingOps = new Operations($db);
$subscription = $billingOps->execute($admin,$billing,'subscription.open','activate',
    $fixture['open']+$fixture['branches'][0])['result']['subscription_id'];
$meterEmail = 'meter.'.$tag.'@example.test';
$user = $identity->addUser($admin,'计量对接人',$meterEmail,$password);
$identity->membership($admin,$billing,$user,'meter',true);
$meter = new WorkbenchClient($base);
$meter->login($meterEmail,$password);
$route = "/subscriptions/$subscription/usage-events?company=$billing";
$event = ['source'=>'S1','event_id'=>'E82','occurred_at'=>'2027-02-28T16:00:00Z','units'=>10,'metric'=>'api_calls'];
$first = $meter->json($route,$event,'first');
same(200,$first['code'],'Canonical HTTP usage endpoint');
$accepted = json_decode($first['body'],true,flags:JSON_THROW_ON_ERROR);
same(['2027-03','accepted'],[$accepted['billing_period'],$accepted['disposition']],'Occurrence in Shanghai determines March');
same($accepted,json_decode($meter->json($route,$event,'first')['body'],true,flags:JSON_THROW_ON_ERROR),'Lost response returns original durable attempt');
foreach (['retry-2','retry-3'] as $key) {
    $duplicate = json_decode($meter->json($route,$event,$key)['body'],true,flags:JSON_THROW_ON_ERROR);
    same([$accepted['usage_id'],'duplicate'],[$duplicate['usage_id'],$duplicate['disposition']],'Delivery retries only add trace evidence');
}
$changed = array_replace($event,['units'=>12]);
$reply = $meter->json($route,$changed,'first');
same(409,$reply['code'],'Changed body at original idempotency key refused');
$conflict = json_decode($reply['body'],true,flags:JSON_THROW_ON_ERROR)['details'];
same([$accepted['usage_id'],'conflict'],[$conflict['usage_id'],$conflict['disposition']],'Conflict references original accepted event');
same($conflict['attempt_id'],json_decode($meter->json($route,$changed,'first')['body'],true,flags:JSON_THROW_ON_ERROR)['details']['attempt_id'],'Repeated conflict keeps one review item');
$queue = $meter->call("/api.php?company=$billing&resource=usage-attempts&id=$subscription");
same([$conflict['attempt_id']],array_column(json_decode($queue['body'],true,flags:JSON_THROW_ON_ERROR)['items'],'id'),'Conflict survives HTTP error and appears in operator queue');
$detail = $meter->call("/api.php?company=$billing&resource=subscriptions&id=$subscription&date=2027-03-01");
$metric = json_decode($detail['body'],true,flags:JSON_THROW_ON_ERROR)['metrics'][0];
same([10,90],[$metric['used_units'],$metric['balance_units']],'Three deliveries of ten units only reduce balance by ten');
$invalid = $meter->json($route,array_replace($event,['units'=>0]),'bad');
same([422,'units'],[$invalid['code'],json_decode($invalid['body'],true,flags:JSON_THROW_ON_ERROR)['details']['field']],'Invalid measurement identifies its field');
same(405,$meter->call($route)['code'],'Usage ingestion is POST only');
same(404,$meter->json("/subscriptions/$subscription/usage-events?company=$b",$event,'wrong-company')['code'],'Foreign ledger remains invisible');
$page = "/?company=$billing&view=subscriptions&id=$subscription&date=2027-03-01";
$inbox = $meter->call($page);
same(200,$inbox['code'],'Meter opens a dated subscription in the workbench');
same(true,str_contains($inbox['body'],'待核查差异') && str_contains($inbox['body'],'E82'),'Operator can see persisted event discrepancy in the UI');
$clientEmail = 'subscriber.'.$tag.'@example.test';
$customerId = $identity->addUser($admin,'客户管理员',$clientEmail,$password);
$identity->membership($admin,$billing,$customerId,'subscriber',true);
$customer = new WorkbenchClient($base);
$customer->login($clientEmail,$password);
same(404,$customer->call($page)['code'],'Customer cannot guess a subscription before grant');
$billingOps->execute($admin,$billing,'subscription.viewer','grant',['id'=>$subscription,'user_id'=>$customerId,'allowed'=>true]);
$customerPage = $customer->call($page);
same(200,$customerPage['code'],'Customer granted the specific subscription can see the balance');
same(false,str_contains($customerPage['body'],'计量接收记录'),'Customer does not see raw integration attempts');
same([$subscription],array_column(json_decode($customer->call("/api.php?company=$billing&resource=subscriptions")['body'],true,flags:JSON_THROW_ON_ERROR)['items'],'subscription_id'),'Register honors individual customer grant');
$billingOps->execute($admin,$billing,'subscription.viewer','revoke',['id'=>$subscription,'user_id'=>$customerId,'allowed'=>false]);
same(404,$customer->call($page)['code'],'Revocation removes detail on the existing session');
$billingOps->execute($admin,$billing,'subscription.viewer','restore',['id'=>$subscription,'user_id'=>$customerId,'allowed'=>true]);
$clients = [];
foreach (['service','finance'] as $role) {
    $login = $role.'.billing.'.$tag.'@example.test';
    $uid = $identity->addUser($admin,$role,$login,$password);
    $identity->membership($admin,$billing,$uid,$role,true);
    if ($role === 'service') {
        $billingOps->execute($admin,$billing,'service.grant','grant-agent',['agreement_id'=>$fixture['agreement'],'user_id'=>$uid,'allowed'=>true]);
    }
    $clients[$role] = new WorkbenchClient($base);
    $clients[$role]->login($login,$password);
}
$additional = json_decode($meter->json($route,array_replace($event,['units'=>110,'event_id'=>'E83','occurred_at'=>'2027-03-12T12:00:00Z']),'last110')['body'],true,flags:JSON_THROW_ON_ERROR);
$post = static function (WorkbenchClient $who,string $action,array $fields,string $key) use ($billing): array {
    $reply = $who->json('/api.php?company='.$billing,['action'=>$action,'data'=>$fields],$key);
    return [$reply['code'],json_decode($reply['body'],true,flags:JSON_THROW_ON_ERROR)];
};
$get = static fn(WorkbenchClient $who,int $id): array => json_decode($who->call("/api.php?company=$billing&resource=statement&id=$id")['body'],true,flags:JSON_THROW_ON_ERROR);
$finance = $clients['finance'];
$agent = $clients['service'];
[$status,$draft] = $post($finance,'billing.draft',['id'=>$subscription,'period'=>'2027-03'],'draft-mar');
same(200,$status,'Finance previews March');
$bill = $draft['result'];
$march = $bill['statement_id'];
$ref = ['id'=>$march,'version'=>$bill['statement_rev']];
$over = array_values(array_filter($get($finance,$march)['lines'],fn($line)=>$line['kind']==='overage'))[0];
same([4000,20,$additional['usage_id']],[$over['amount_minor'],$over['quantity'],$over['usage_id']],'120 total units yield 40 currency units above quota');
same(200,$post($finance,'billing.lock',$ref+['digest'=>$bill['digest']],'lock-mar')[0],'Finance locks March');
$dispute = $post($agent,'dispute.raise',$ref+['line_id'=>$over['id'],'units'=>5,'reason'=>'核实五次重复调用'],'dispute')[1]['result'];
$decision = $ref+['dispute_id'=>$dispute['dispute_id'],'dispute_rev'=>1,'decision'=>'held','reason'=>'等待服务端复核'];
same(403,$post($agent,'dispute.decide',$decision,'invalid-decision')[0],'Service cannot decide its own dispute');
same(200,$post($finance,'dispute.decide',$decision,'hold')[0],'Finance holds disputed units');
same(1000,$get($finance,$march)['disputed_minor'],'Only ten currency units frozen');
same(409,$post($finance,'billing.payment',$ref+['amount'=>'140','received_on'=>'2027-04-01','receipt_no'=>'BANK-ONE'],'bank-block')[0],'Collection of held amount rejected');
same(200,$post($finance,'billing.payment',$ref+['amount'=>'130','received_on'=>'2027-04-01','receipt_no'=>'BANK-ONE'],'bank-130')[0],'Undisputed balance can be settled');
same(13000,$get($finance,$march)['paid_minor'],'Bank receipt stored in minor units');
same($march,$post($finance,'billing.draft',['id'=>$subscription,'period'=>'2027-03'],'repeat-mar')[1]['result']['statement_id'],'Rerun during hold cannot issue another bill');
same(0,$get($finance,$march)['collectible_minor'],'No collectible balance while difference is held');
$acceptedCredit = $post($finance,'dispute.decide',array_replace($decision,['dispute_rev'=>2,'decision'=>'credited','reason'=>'五次重复调用已确认']),'decide')[1]['result'];
$april = $post($finance,'billing.draft',['id'=>$subscription,'period'=>'2027-04'],'draft-apr')[1]['result'];
$aprilId = $april['statement_id'];
same(9000,$april['total_minor'],'April fixed fee 100 minus credit 10');
$post($finance,'billing.lock',['id'=>$aprilId,'version'=>$april['statement_rev'],'digest'=>$april['digest']],'lock-apr');
same(404,$meter->call("/api.php?company=$billing&resource=statement&id=$march")['code'],'Meter role cannot read billing records');
$seenCredits = [];
foreach (['service'=>$agent,'finance'=>$finance,'subscriber'=>$customer] as $role=>$reader) {
    $previous = $get($reader,$march);
    $next = $get($reader,$aprilId);
    same(4000,array_values(array_filter($previous['lines'],fn($l)=>$l['kind']==='overage'))[0]['amount_minor'],'Original locked amount remains 40 for each reader');
    $seenCredits[$role] = $next['credits'][0]['id'];
    $negative = array_values(array_filter($next['lines'],fn($l)=>$l['kind']==='adjustment'))[0];
    same([-1000,$additional['usage_id'],$over['id']],[$negative['amount_minor'],$negative['usage_id'],json_decode($negative['detail'],true)['original_line']],'Next statement traces minus ten to original usage and bill line');
    same([$acceptedCredit['credit_id'],$acceptedCredit['credit_id']],[$previous['credits'][0]['id'],$next['credits'][0]['id']],'All roles trace the same adjustment ID');
    $screen = $reader->call($page.'&statement='.$aprilId);
    same(true,$screen['code']===200 && str_contains($screen['body'],'跨期调整') && str_contains($screen['body'],'-10.00'),'April adjustment visible in authenticated HTML');
}
$csv = $finance->call("/export.php?company=$billing&statement=$aprilId");
same(true,$csv['code']===200 && str_contains($csv['body'],'adjustment') && str_contains($csv['body'],'-1000'),'Finance can export the reviewed invoice and its credit');
same(403,$customer->call("/export.php?company=$billing&statement=$aprilId")['code'],'Private invoice interface requires finance permission');
$paymentCsv = "statement_id,revision,receipt_no,received_on,amount\n$aprilId,1,APR-1,2027-05-02,42.50\n";
same(409,$post($finance,'billing.import-payments',['csv'=>$paymentCsv."$aprilId,1,APR-2,2027-05-02,60.00\n"],'batch-fail')[0],'One bad payment rolls back earlier rows in the same file');
same(0,$get($finance,$aprilId)['paid_minor'],'Failed import leaves no partial collection');
same(200,$post($finance,'billing.import-payments',['csv'=>$paymentCsv],'batch-ok')[0],'Finance imports bank statement CSV');
same(200,$post($finance,'billing.import-payments',['csv'=>$paymentCsv],'batch-repeat')[0],'Payment import may be safely redelivered');
same(4250,$get($finance,$aprilId)['paid_minor'],'Actual bank receipt reconciled only once');
if ($evidenceFile=getenv('CRM_SUBSCRIPTION_EVIDENCE')) {
    $details=$get($customer,$aprilId);
    file_put_contents($evidenceFile,json_encode(['input'=>[$event,array_replace($event,['units'=>110,'event_id'=>'E83','occurred_at'=>'2027-03-12T12:00:00Z'])],
        'accepted'=>[$accepted,$additional],'conflict_payload'=>$changed,'conflict_receipt'=>$conflict,'original_overage'=>$over,
        'review_decision'=>$acceptedCredit,'april_lines'=>$details['lines'],'same_credit_id_seen_by'=>$seenCredits,'bank_receipts'=>$details['payments']],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
}
$billingOps->execute($admin,$billing,'pilot.change','stop',['version'=>1,'state'=>'draining']);
same(409,$meter->json($route,array_replace($event,['event_id'=>'E84']),'after-stop')['code'],'Draining stops first-time metering');
same($accepted['usage_id'],json_decode($meter->json($route,$event,'first')['body'],true,flags:JSON_THROW_ON_ERROR)['usage_id'],'Prior receipt survives draining');
same(true,str_contains($finance->call("/export.php?company=$billing&kind=billing-open")['body'],'usage_conflict'),'Open conflict export includes held item');
same(200,$post($meter,'usage.review',['id'=>$subscription,'attempt_id'=>$conflict['attempt_id'],'reason'=>'使用 S1 的原始事件，拒绝修改数量'],'review')[0],'Operator can drain earlier conflict');
same(false,str_contains($finance->call("/export.php?company=$billing&kind=billing-open")['body'],'usage_conflict'),'Resolved conflict no longer outstanding');
