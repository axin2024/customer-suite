<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Campaigns;
use CustomerSuite\Contacts;
use CustomerSuite\Forecasts;
use CustomerSuite\Identity;
use CustomerSuite\LeadIntake;
use CustomerSuite\Leads;
use CustomerSuite\Money;
use CustomerSuite\Opportunities;
use CustomerSuite\Operations;

require __DIR__ . '/bootstrap.php';

$tag = bin2hex(random_bytes(5));
$password = 'Test-only-' . bin2hex(random_bytes(16));
$identity = new Identity($db);
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 LIMIT 1');
$admin = new Access($db, $root ? (int) $root['id'] : $identity->bootstrap('系统管理员', $tag . '@example.test', $password));
$sellerId = $identity->addUser($admin, '销售甲', $tag . '.a@example.test', $password);
$otherId = $identity->addUser($admin, '销售乙', $tag . '.b@example.test', $password);
$sales = new Access($db, $sellerId);
$other = new Access($db, $otherId);
$a = $identity->addCompany($admin, 'A-S-' . $tag, '演示销售甲');
$b = $identity->addCompany($admin, 'B-S-' . $tag, '演示销售乙');
$identity->membership($admin, $a, $sellerId, 'sales', true);
$identity->membership($admin, $a, $otherId, 'sales', true);
$identity->membership($admin, $b, $otherId, 'sales', true);
$accounts = new Accounts($db);
$account = $accounts->create($sales, $a, ['name' => '甲公司销售客户']);
$peer = $accounts->create($other, $a, ['name' => '销售乙同公司客户']);
$contacts = new Contacts($db);
$decider = $contacts->save($sales, $a, $account['id'], ['name' => '虚构决策人', 'job_title' => '采购负责人']);
$wrongDecider = $contacts->save($other, $a, $peer['id'], ['name' => '虚构其他联系人', 'job_title' => '采购负责人']);
$campaign = (new Campaigns($db))->create($admin, $a, ['code' => 'WEB-Q', 'name' => '线上报价请求', 'channel' => 'web']);
$lead = (new LeadIntake($db))->receive($admin, $a, [
    'campaign_id' => $campaign['id'], 'name' => '试用展项', 'email' => 'demo-lead@example.test',
    'source' => 'fixture', 'external_id' => 'DEMO-Q1', 'occurred_at' => '2026-11-20T12:30:00Z',
]);
$leads = new Leads($db);
$assigned = $leads->claim($sales, $a, $lead['id'], $lead['version']);
$qualified = $leads->qualify($sales, $a, $lead['id'], $assigned['version'], $account['id'], '2026-11-29');
$opportunities = new Opportunities($db);
$forecasts = new Forecasts($db);
$request = new Operations($db);
$leadConversion = [
    'account_id' => $account['id'], 'lead_id' => $qualified['id'], 'lead_version' => $qualified['version'],
    'title' => '储能柜监控升级', 'currency' => 'CNY', 'amount' => '1000000.00', 'expected_close' => '2026-12-18',
];
$opportunity = $request->execute($sales, $a, 'opportunity.create', 'quote-prepare', $leadConversion)['result'];
$id = $opportunity['id'];
same('converted', $leads->detail($sales, $a, $lead['id'])['state'], 'Lead conversion is part of the opportunity transaction.');
same($lead['id'], $opportunities->detail($sales, $a, $id)['lead_id'], 'Opportunity links source lead.');
same($id, $request->execute($sales, $a, 'opportunity.create', 'quote-prepare', $leadConversion)['result']['id'], 'Retry preserves opportunity ID.');
rejects(409, fn () => $opportunities->create($sales, $a, $leadConversion), 'A converted lead cannot generate a second opportunity.');
rejects(404, fn () => $opportunities->detail($other, $a, $id), 'Peer salesperson cannot see another customer opportunity.');
rejects(404, fn () => $opportunities->detail($other, $b, $id), 'Sibling salesperson cannot read using mismatched company.');
rejects(422, fn () => $opportunities->move($sales, $a, $id, 1, ['stage' => 'qualified']), 'Qualification requires decision contact.');
rejects(422, fn () => $opportunities->move($sales, $a, $id, 1, [
    'stage' => 'qualified', 'decision_contact_id' => $wrongDecider['id'],
]), 'Decision contact must belong to the customer.');
$next = $opportunities->move($sales, $a, $id, 1, ['stage' => 'qualified', 'decision_contact_id' => $decider['id']]);
same(2500, $next['probability_bps'], 'Qualification has configured probability.');
rejects(422, fn () => $opportunities->move($sales, $a, $id, $next['version'], ['stage' => 'proposal']), 'Proposal requires products.');
$products = [
    ['product_code' => 'WAREHOUSE-CONNECT', 'product_version' => '2.3', 'description' => '仓库连接许可', 'quantity' => 10, 'unit_price' => '80000'],
    ['product_code' => 'DELIVERY', 'product_version' => '1.0', 'description' => '部署实施服务', 'quantity' => 1, 'unit_price' => '200000'],
];
$withProducts = $request->execute($sales, $a, 'opportunity.products', 'product-details', [
    'id' => (string) $id, 'version' => (string) $next['version'], 'products' => json_encode($products, JSON_THROW_ON_ERROR),
])['result'];
same(100000000, $withProducts['value_minor'], 'Product quantities calculate exact CNY minor units.');
$proposal = $opportunities->move($sales, $a, $id, $withProducts['version'], ['stage' => 'proposal']);
$negotiation = $opportunities->move($sales, $a, $id, $proposal['version'], ['stage' => 'negotiation', 'next_follow_up' => '2026-12-03']);
same(6000, $negotiation['probability_bps'], 'Negotiation weight is 60 percent.');
same('2.3', $opportunities->detail($sales, $a, $id)['products'][0]['product_version'], 'Product revision retained on line.');
same('2026-12-03', $opportunities->detail($sales, $a, $id)['next_follow_up'], 'Follow-up date available to owner.');
same(5, count($opportunities->detail($sales, $a, $id)['timeline']), 'Versioned timeline includes product and stage mutations.');
$input = ['label' => '12 月上旬预测', 'period_start' => '2026-12-01', 'period_end' => '2026-12-31'];
rejects(403, fn () => $forecasts->capture($sales, $a, $input), 'Only manager can freeze company forecast.');
$snapshot = $forecasts->capture($admin, $a, $input);
same(1, $snapshot['opportunity_count'], 'Snapshot copied one company row.');
$previous = $forecasts->detail($sales, $a, $snapshot['id']);
same(60000000, $previous['items'][0]['weighted_minor'], 'AC-05 CNY 1,000,000 at 60% yields CNY 600,000.');
same('600000.00', Money::format($previous['items'][0]['weighted_minor'], 'CNY'), 'Currency format preserves cents.');
rejects(404, fn () => $forecasts->detail($other, $a, $snapshot['id']), 'No snapshot read for sales owner with no visible records.');
rejects(404, fn () => $forecasts->detail($other, $b, $snapshot['id']), 'Cross company snapshot read rejected.');
rejects(422, fn () => $opportunities->move($sales, $a, $id, $negotiation['version'], ['stage' => 'proposal']), 'Rollback requires a reason.');
$rolledBack = $opportunities->move($sales, $a, $id, $negotiation['version'], ['stage' => 'proposal', 'reason' => '重新核对范围']);
same(4000, $rolledBack['probability_bps'], 'Rollback resets current stage weight.');
$updated = $forecasts->capture($admin, $a, array_replace($input, ['label' => '12 月中旬预测']));
$diff = $forecasts->compare($admin, $a, $snapshot['id'], $updated['id'])['changes'];
same(60000000, $diff[0]['previous_weighted_minor'], 'AC-06 Old forecast stays unchanged after rollback.');
same(40000000, $diff[0]['current_weighted_minor'], 'AC-06 New forecast incorporates current weight.');
same('negotiation', $previous['items'][0]['stage'], 'Old opportunity stage retained on snapshot.');
same('proposal', $opportunities->list($sales, $a, 'proposal')['items'][0]['stage'], 'Current work list moves to proposal.');
same([], $opportunities->list($other, $a, 'proposal')['items'], 'Peer salesperson has no matching item.');
$accounts->revise($admin, $a, $account['id'], 1, $otherId, null);
rejects(404, fn () => $forecasts->detail($sales, $a, $snapshot['id']), 'Snapshot ownership cannot bypass current account ownership.');
rejects(403, fn () => $opportunities->updateProducts($sales, $a, $id, $rolledBack['version'], $products), 'Former account owner cannot change product lines.');
same(12, Money::minor('12', '报价', 'JPY'), 'JPY uses whole monetary units.');
rejects(422, fn () => Money::minor('12.5', '报价', 'JPY'), 'Japanese yen rejects fractional units.');
done('Sales conversion, stages and immutable forecast');
