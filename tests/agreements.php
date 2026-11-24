<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Contacts;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Queries;

require __DIR__ . '/bootstrap.php';

$tag = bin2hex(random_bytes(5));
$password = 'Test-only-' . bin2hex(random_bytes(16));
$identity = new Identity($db);
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
$admin = new Access($db, $root['id'] ?? $identity->bootstrap('系统管理员', $tag . '@example.test', $password));
$createUser = static fn (string $name, string $role): Access => new Access($db,
    $identity->addUser($admin, $name, "$tag.$role@example.test", $password));
$manager = $createUser('集团客户经理', 'manager');
$reviewer = $createUser('独立审批人', 'reviewer');
$aSales = $createUser('甲分公司销售', 'salesa');
$bSales = $createUser('乙分公司销售', 'salesb');
$hq = $identity->addCompany($admin, 'HQ-Q-' . $tag, '集团法务总部');
$a = $identity->addCompany($admin, 'A-Q-' . $tag, '甲签约公司', $hq);
$b = $identity->addCompany($admin, 'B-Q-' . $tag, '乙签约公司', $hq);
$c = $identity->addCompany($admin, 'C-Q-' . $tag, '丙签约公司', $hq);
foreach ([$hq, $a, $b] as $company) {
    $identity->membership($admin, $company, $manager->userId, 'manager', true);
}
foreach ([$hq, $a] as $company) {
    $identity->membership($admin, $company, $reviewer->userId, 'approver', true);
}
$identity->membership($admin, $a, $aSales->userId, 'sales', true);
$identity->membership($admin, $b, $bSales->userId, 'sales', true);
$accounts = new Accounts($db);
$parent = $accounts->create($manager, $hq, ['name' => '北河科技集团', 'registration_ref' => 'GROUP-NH']);
$parentLink = ['parent_company_id' => $hq, 'parent_id' => $parent['id']];
$aAccount = $accounts->create($manager, $a, ['name' => '北河科技甲公司', 'registration_ref' => 'NH-A', 'owner_id' => $aSales->userId] + $parentLink);
$bAccount = $accounts->create($manager, $b, ['name' => '北河科技乙公司', 'registration_ref' => 'NH-B', 'owner_id' => $bSales->userId] + $parentLink);
$cAccount = $accounts->create($admin, $c, ['name' => '北河科技丙公司', 'registration_ref' => 'NH-C'] + $parentLink);
$contact = (new Contacts($db))->save($manager, $hq, $parent['id'], ['name' => '项目采购负责人', 'job_title' => '采购经理']);
$operations = new Operations($db);
$queries = new Queries($db);
$write = static fn (Access $actor, int $company, string $action, array $data, ?string $key = null): array =>
    $operations->execute($actor, $company, $action, $key ?? bin2hex(random_bytes(12)), $data)['result'];
$today = new DateTimeImmutable($db->row('SELECT UTC_DATE() AS day')['day'], new DateTimeZone('UTC'));
$expires = $today->format('Y-m-d');
$later = $today->modify('+60 days')->format('Y-m-d');
$end = $today->modify('+1 year')->format('Y-m-d');
$opportunity = $write($manager, $hq, 'opportunity.create', [
    'account_id' => $parent['id'], 'title' => '园区系统集团采购', 'currency' => 'CNY', 'amount' => '0', 'expected_close' => $later,
]);
$opp = ['id' => $opportunity['id']];
$write($manager, $hq, 'opportunity.move', $opp + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $contact['id']]);
$product = ['product_code' => 'PARK-CORE', 'product_version' => '1.4', 'description' => '园区基础系统', 'quantity' => 10, 'unit_price' => '1000'];
$write($manager, $hq, 'opportunity.products', $opp + ['version' => 2, 'products' => [$product]]);
$write($manager, $hq, 'opportunity.move', $opp + ['version' => 3, 'stage' => 'proposal']);
$write($manager, $hq, 'quote.policy', ['self_approval_bps' => 800]);
$base = ['opportunity_id' => $opportunity['id']];
$draft = $write($manager, $hq, 'quote.prepare', $base + [
    'opportunity_version' => 4, 'discount_bps' => 1000, 'expires_on' => $expires, 'terms' => '第一版集团部署条款',
]);
$key = ['id' => $draft['id']];
$pending = $write($manager, $hq, 'quote.submit', $key + ['version' => $draft['version']]);
same('pending', $pending['state'], 'An above-limit quote needs approval.');
$decision = $key + ['version' => 2, 'decision' => 'approved', 'reason' => '集团采购批量折扣'];
rejects(403, fn () => $write($manager, $hq, 'quote.decide', $decision), 'A manager cannot approve their own discount.');
same([$draft['id']], array_column($queries->read($reviewer, $hq, 'quotes', [])['items'], 'id'), 'Pending quote appears in reviewer worklist.');
$write($manager, $hq, 'quote.policy', ['self_approval_bps' => 1500]);
$approved = $write($reviewer, $hq, 'quote.decide', $decision);
same('approved', $approved['state'], 'Independent approver records decision.');
$view = $queries->read($reviewer, $hq, 'quotes', $key);
same(800, $view['self_approval_bps'], 'Approval policy is pinned to the submission version.');
same($reviewer->userId, $view['decided_by'], 'Reviewer sees their own decided quote.');
same(900000, $view['net_minor'], 'Quote subtotal and discount compute exact cents.');

$allocation = [
    'amount' => '3000.00', 'starts_on' => $expires, 'ends_on' => $end, 'service_level' => 'priority', 'evidence_ref' => '纸质签收-S1',
];
$rows = [
    ['company_id' => $a, 'account_id' => $aAccount['id'], 'reference_no' => 'A-0001'] + $allocation,
    ['company_id' => $b, 'account_id' => $bAccount['id'], 'reference_no' => 'B-0001'] + $allocation,
    ['company_id' => $c, 'account_id' => $cAccount['id'], 'reference_no' => 'C-0001'] + $allocation,
];
$sign = $key + ['version' => 3, 'scope' => 'group', 'allocations' => $rows];
try {
    $db->run('SET timestamp = ' . $today->modify('+1 day')->getTimestamp());
    $expired = rejects(409, fn () => $write($manager, $hq, 'agreement.sign', $sign), 'AC-08 Approved quote cannot be signed after expiry.');
    same('报价已过期，请先创建新修订。', $expired->getMessage(), 'Expired quote provides re-quote instruction.');
} finally {
    $db->run('SET timestamp = 0');
}
$nextProduct = array_replace($product, ['product_version' => '2.0']);
$changed = $write($manager, $hq, 'opportunity.products', $opp + ['version' => 4, 'products' => [$nextProduct]]);
$newDraft = $write($manager, $hq, 'quote.prepare', $base + [
    'version' => 3, 'opportunity_version' => $changed['version'], 'discount_bps' => 1000, 'expires_on' => $later, 'terms' => '第二版集团部署条款',
]);
same(2, $newDraft['revision'], 'Re-quote appends a version.');
same('1.4', $queries->read($manager, $hq, 'quotes', $key + ['revision' => 1])['products'][0]['product_version'], 'Historical product snapshot stays immutable.');
same('2.0', $queries->read($manager, $hq, 'quotes', $key)['products'][0]['product_version'], 'New quote takes the updated product version.');
same('archived', $queries->read($reviewer, $hq, 'quotes', $key + ['revision' => 1])['state'], 'Old approval remains reviewable after re-quote.');
rejects(404, fn () => $queries->read($reviewer, $hq, 'quotes', $key), 'Approver cannot read another current draft using an old decision.');
$ready = $write($manager, $hq, 'quote.submit', $key + ['version' => $newDraft['version']]);
same('approved', $ready['state'], 'New submission uses new delegated discount limit.');
$sign['version'] = $ready['version'];
$wrong = $sign;
$wrong['allocations'][2]['amount'] = '2999.99';
rejects(422, fn () => $write($manager, $hq, 'agreement.sign', $wrong), 'Allocation must balance to the cent.');
rejects(404, fn () => $write($manager, $hq, 'agreement.sign', $sign), 'Group signing requires authority at every target even after prior targets were processed.');
same(0, $db->row('SELECT COUNT(*) AS n FROM agreements WHERE source_company_id = ? AND quote_id = ?', [$hq, $draft['id']])['n'], 'Denied final allocation rolls back the entire transaction.');
same('approved', $queries->read($manager, $hq, 'quotes', $key)['state'], 'Failed group signing leaves the quote actionable.');
$identity->membership($admin, $c, $manager->userId, 'manager', true);
$signed = $write($manager, $hq, 'agreement.sign', $sign, 'group-sign');
same(3, $signed['agreement_count'], 'AC-07 Exactly three subsidiaries signed.');
same(Input::digest($signed), Input::digest($write($manager, $hq, 'agreement.sign', $sign, 'group-sign')), 'A replay returns the original group result.');
rejects(409, fn () => $write($manager, $hq, 'agreement.sign', $sign), 'New request cannot issue another contract set.');
$contracts = $db->rows('SELECT id,company_id,account_id,quote_revision FROM agreements WHERE source_company_id = ? AND quote_id = ? ORDER BY company_id', [$hq, $draft['id']]);
same([$aAccount['id'], $bAccount['id'], $cAccount['id']], array_column($contracts, 'account_id'), 'AC-01 Separate legal customer appears on each signed record.');
same([2, 2, 2], array_column($contracts, 'quote_revision'), 'Each signed agreement is pinned to the same approved historical quote.');
same(900000, (int) $db->row('SELECT SUM(v.value_minor) AS allocated FROM agreements a JOIN agreement_versions v ON v.company_id = a.company_id
    AND v.agreement_id = a.id AND v.revision = 1 WHERE a.source_company_id = ? AND a.quote_id = ?', [$hq, $draft['id']])['allocated'], 'Allocated value balances in stored versions.');
$contractA = ['id' => $contracts[0]['id']];
same([$contractA['id']], array_column($queries->read($aSales, $a, 'agreements', [])['items'], 'id'), 'Seller sees only its own legal company contract.');
same(false, array_key_exists('quote_id', $queries->read($aSales, $a, 'agreements', $contractA)), 'Subsidiary seller has no access to group quotation reference.');
rejects(404, fn () => $queries->read($aSales, $a, 'agreements', ['id' => $contracts[1]['id']]), 'Sibling contract cannot be read using current company.');
rejects(404, fn () => $queries->read($reviewer, $a, 'agreements', $contractA), 'An unrelated approved original is hidden from the approver.');
$original = $db->row('SELECT * FROM agreement_versions WHERE company_id = ? AND agreement_id = ? AND revision = 1', [$a, $contractA['id']]);
$amendment = $contractA + ['version' => 1, 'amount' => '3168.25', 'starts_on' => $expires, 'ends_on' => $end,
    'service_level' => 'critical', 'terms' => '增加关键业务支持', 'reason' => '客户迁移核心业务'];
same('pending', $write($aSales, $a, 'agreement.propose', $amendment)['decision'], 'Seller can submit a scoped supplementary agreement.');
same(1, $queries->read($aSales, $a, 'agreements', $contractA)['revision'], 'Pending amendment does not replace active terms.');
same(316825, $queries->read($reviewer, $a, 'agreements', [])['items'][0]['value_minor'], 'Approver worklist shows proposed value.');
$amendDecision = $contractA + ['version' => 2, 'decision' => 'signed', 'reason' => '核对纸质复核签章', 'evidence_ref' => '纸质补充协议-A2'];
$identity->membership($admin, $a, $aSales->userId, 'approver', true);
rejects(403, fn () => $write($aSales, $a, 'agreement.decide', $amendDecision), 'Amendment preparer cannot countersign own record even with approver role.');
same(2, $write($reviewer, $a, 'agreement.decide', $amendDecision)['active_revision'], 'Signed amendment is now active.');
same($original, $db->row('SELECT * FROM agreement_versions WHERE company_id = ? AND agreement_id = ? AND revision = 1', [$a, $contractA['id']]), 'Original signing revision and evidence remain byte-identical.');
same(316825, $queries->read($aSales, $a, 'agreements', $contractA)['value_minor'], 'Current contract exposes approved amended conditions.');
same('fulfilling', $write($aSales, $a, 'agreement.move', $contractA + ['version' => 3, 'state' => 'fulfilling', 'reason' => '签署后通知交付'])['state'], 'Contract moves into delivery.');
$target = $accounts->create($manager, $a, ['name' => '甲公司当前主档', 'registration_ref' => 'NH-A', 'owner_id' => $aSales->userId] + $parentLink);
$accounts->merge($manager, $a, $target['id'], 1, $aAccount['id'], 1);
$afterMerge = $queries->read($aSales, $a, 'agreements', $contractA + ['revision' => 1]);
same($aAccount['id'], $afterMerge['account_id'], 'Account merge does not move a historic agreement.');
same('北河科技甲公司', $afterMerge['legal_name'], 'Original signing name stays on the agreement.');
same(['agreement.sign', 'agreement.propose', 'agreement.decide', 'agreement.move'], array_column($afterMerge['timeline'], 'action'), 'Audit reveals the true actors and all four durable actions.');
same($reviewer->userId, $afterMerge['timeline'][2]['actor_id'], 'The countersign action identifies the real reviewing actor.');
done('Group quote expiry, discount workflow, signing and amendments');
