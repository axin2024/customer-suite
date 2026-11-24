<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Contacts;
use CustomerSuite\Handovers;
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
$a = $identity->addCompany($admin, 'A-D-' . $tag, '甲交付公司');
$b = $identity->addCompany($admin, 'B-D-' . $tag, '乙交付公司');
$createActor = static function (int $company, string $name, string $local, string $role) use ($identity, $admin, $db, $tag, $password): Access {
    $id = $identity->addUser($admin, $name, "$tag.$local@example.test", $password);
    $identity->membership($admin, $company, $id, $role, true);
    return new Access($db, $id);
};
$sales = $createActor($a, '甲销售', 'salesa', 'sales');
$oldOwner = $createActor($a, '原实施人员', 'old', 'delivery');
$owner = $createActor($a, '现实施人员', 'owner', 'delivery');
$other = $createActor($b, '乙实施人员', 'other', 'delivery');
$identity->membership($admin, $a, $sales->userId, 'delivery', true);
$ops = new Operations($db);
$queries = new Queries($db);
$write = static fn (Access $actor, int $company, string $action, array $data, ?string $key = null): array =>
    $ops->execute($actor, $company, $action, $key ?? bin2hex(random_bytes(12)), $data)['result'];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$today = $now->format('Y-m-d');
$due = $now->modify('+30 days')->format('Y-m-d');
$delayedDue = $now->modify('+35 days')->format('Y-m-d');
$end = $now->modify('+1 year')->format('Y-m-d');
$contacts = new Contacts($db);
$accounts = new Accounts($db);
$newSigned = static function (Access $seller, int $company, string $name) use ($accounts, $contacts, $write, $db, $today, $due, $end): array {
    $account = $accounts->create($seller, $company, ['name' => $name]);
    $contact = $contacts->save($seller, $company, $account['id'], ['name' => '虚构项目代表', 'job_title' => '项目经理']);
    $opp = ['id' => $write($seller, $company, 'opportunity.create', [
        'account_id' => $account['id'], 'title' => '实施服务签约', 'currency' => 'CNY', 'amount' => '1', 'expected_close' => $due,
    ])['id']];
    $write($seller, $company, 'opportunity.move', $opp + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $contact['id']]);
    $write($seller, $company, 'opportunity.products', $opp + ['version' => 2, 'products' => [[
        'product_code' => 'DELIVERY', 'product_version' => '1', 'description' => '实施服务', 'quantity' => 1, 'unit_price' => '2000',
    ]]]);
    $write($seller, $company, 'opportunity.move', $opp + ['version' => 3, 'stage' => 'proposal']);
    $quote = ['id' => $write($seller, $company, 'quote.prepare', ['opportunity_id' => $opp['id'], 'opportunity_version' => 4, 'discount_bps' => 0,
        'expires_on' => $due, 'terms' => '甲乙独立签约与实施'])['id']];
    $write($seller, $company, 'quote.submit', $quote + ['version' => 1]);
    $write($seller, $company, 'agreement.sign', $quote + ['version' => 2, 'scope' => 'single', 'allocations' => [[
        'company_id' => $company, 'account_id' => $account['id'], 'reference_no' => 'DELIVERY-1', 'amount' => '2000',
        'starts_on' => $today, 'ends_on' => $end, 'service_level' => 'standard', 'evidence_ref' => '签署凭据-1',
    ]]]);
    $contract = $db->row('SELECT id FROM agreements WHERE company_id = ? AND quote_id = ?', [$company, $quote['id']]);
    return ['contract' => $contract['id'], 'contact' => $contact['id'], 'account' => $account['id']];
};
$aSigned = $newSigned($sales, $a, '甲客户服务合同');
$bSigned = $newSigned($admin, $b, '乙客户服务合同');
$open = ['agreement_id' => $aSigned['contract'], 'agreement_version' => 1, 'agreement_revision' => 1, 'owner_id' => $oldOwner->userId,
    'contact_id' => $aSigned['contact'], 'scope' => '软件配置及验收培训'];
rejects(422, fn () => $write($sales, $a, 'handover.open', array_replace($open, ['owner_id' => $other->userId])), 'Sibling delivery staff cannot be assigned without membership.');
$first = ['id' => $write($sales, $a, 'handover.open', $open)['id']];
rejects(409, fn () => $write($sales, $a, 'handover.open', $open), 'Single handover per signed revision.');
$second = ['id' => $write($admin, $b, 'handover.open', [
    'agreement_id' => $bSigned['contract'], 'agreement_version' => 1, 'agreement_revision' => 1,
    'owner_id' => $other->userId, 'contact_id' => $bSigned['contact'], 'scope' => '乙独立验收与回访',
])['id']];
$write($admin, $b, 'handover.confirm', $second + ['version' => 1, 'side' => 'sales']);
$write($other, $b, 'handover.confirm', $second + ['version' => 2, 'side' => 'delivery']);
$write($other, $b, 'handover.milestone.add', $second + ['version' => 3, 'title' => '乙分公司独立待办', 'due_on' => $due]);

rejects(409, fn () => $write($oldOwner, $a, 'handover.milestone.add', $first + ['version' => 1, 'title' => '抢先排期', 'due_on' => $due]), 'Scope needs dual confirmation before execution.');
$write($sales, $a, 'handover.confirm', $first + ['version' => 1, 'side' => 'sales']);
$revised = $write($sales, $a, 'handover.revise', $first + ['version' => 2, 'contact_id' => $aSigned['contact'], 'scope' => '软件配置、培训并增加备份演练']);
same(2, $revised['scope_revision'], 'Scope and contact snapshots are revised together.');
same([], $queries->read($sales, $a, 'handovers', $first)['confirmations'], 'Changing scope invalidates prior approval while preserving history.');
$write($sales, $a, 'handover.confirm', $first + ['version' => 3, 'side' => 'sales']);
$executing = $write($oldOwner, $a, 'handover.confirm', $first + ['version' => 4, 'side' => 'delivery']);
same('executing', $executing['state'], 'Distinct sales and delivery operators open execution.');
$milestone = $write($oldOwner, $a, 'handover.milestone.add', $first + ['version' => 5, 'title' => '上线培训', 'due_on' => $due]);
$state = $write($admin, $a, 'handover.assign', $first + ['version' => 6, 'owner_id' => $owner->userId, 'reason' => '轮岗交接']);
$requestedDelay = $first + ['version' => $state['version'], 'milestone_id' => $milestone['milestone_id'], 'due_on' => $delayedDue,
    'impact' => '培训顺延五天，迁移窗口维持原定计划', 'pending_item' => '客户确认培训场地'];
rejects(403, fn () => $write($oldOwner, $a, 'handover.milestone.delay', $requestedDelay), 'AC-10 Former owner cannot mutate even with freshly requested version.');
rejects(404, fn () => $queries->read($oldOwner, $a, 'handovers', $first), 'Former delivery role alone no longer grants read.');
rejects(409, fn () => $write($owner, $a, 'handover.milestone.delay', $requestedDelay), 'New owner must confirm before advancing work.');
$confirmed = $write($owner, $a, 'handover.confirm', $first + ['version' => 7, 'side' => 'delivery']);
$delay = $write($owner, $a, 'handover.milestone.delay', array_replace($requestedDelay, ['version' => $confirmed['version']]));
same(1, count($queries->read($owner, $a, 'handovers', $first)['issues']), 'Delay stores an independently actionable issue.');
same($due, $queries->read($owner, $a, 'handovers', $first)['delays'][0]['old_due_on'], 'Delay history preserves original due date.');
$complete = $write($owner, $a, 'handover.milestone.complete', $first + ['version' => $delay['version'], 'milestone_id' => $milestone['milestone_id']]);
$file = "验收签收：复核管理员角色及备份演练。\r\n";
$receipt = $first + ['version' => $complete['version'], 'agreement_revision' => 1, 'contact_id' => $aSigned['contact'],
    'confirmed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
    'evidence_reference' => 'CUSTOMER-ACCEPT-1', 'file_name' => '合成签收.txt', 'file_base64' => base64_encode($file)];
rejects(409, fn () => $write($owner, $a, 'handover.accept', $receipt), 'Open issue prevents acceptance after milestone completion.');
$resolved = $write($owner, $a, 'handover.issue.resolve', $first + ['version' => $complete['version'], 'issue_id' => $delay['issue_id'], 'resolution' => '客户确认会议室已预留。']);
$receipt['version'] = $resolved['version'];
$amend = ['id' => $aSigned['contract'], 'version' => 1, 'amount' => '2100', 'starts_on' => $today, 'ends_on' => $end,
    'service_level' => 'priority', 'terms' => '补充协议独立于原交付工作单', 'reason' => '补充服务级别'];
$write($sales, $a, 'agreement.propose', $amend);
$write($admin, $a, 'agreement.decide', ['id' => $aSigned['contract'], 'version' => 2, 'decision' => 'signed', 'reason' => '核对签署档案', 'evidence_ref' => '补充签署-2']);
rejects(409, fn () => $write($owner, $a, 'handover.accept', array_replace($receipt, ['agreement_revision' => 2])), 'Acceptance cannot follow a new contract version implicitly.');
rejects(415, fn () => $write($owner, $a, 'handover.accept', array_replace($receipt, ['file_name' => 'not-a-pdf.pdf'])), 'Fake PDF attachment does not enter archival storage.');
rejects(422, fn () => $write($owner, $a, 'handover.accept', array_replace($receipt, ['contact_id' => $bSigned['contact']])), 'Cannot attribute acceptance to a sibling contact.');
$accepted = $write($owner, $a, 'handover.accept', $receipt, 'fixture-accept');
same('accepted', $accepted['state'], 'AC-09 Company A accepted.');
same(hash('sha256', $file), $accepted['sha256'], 'Receipt contains SHA-256 of evidence bytes.');
same(1, $accepted['agreement_revision'], 'Historic handover stays pinned to signed agreement revision one.');
same(Input::digest($accepted), Input::digest($write($owner, $a, 'handover.accept', $receipt, 'fixture-accept')), 'Confirmed receipt survives a retry.');
rejects(409, fn () => $write($owner, $a, 'handover.accept', array_replace($receipt, ['version' => $accepted['version']])), 'Different request cannot record another acceptance.');
$work = $queries->read($other, $b, 'handovers', []);
same([$second['id']], array_column($work['items'], 'id'), 'AC-09 Company B retains its independent working list.');
same('乙分公司独立待办', $queries->read($other, $b, 'handovers', $second)['milestones'][0]['title'], 'Sibling progress unaffected by company A acceptance.');
same([], $queries->read($other, $b, 'handovers', ['state' => 'accepted'])['items'], 'Sibling reviewer does not see completed work of company A.');
$handovers = new Handovers($db);
same($file, $handovers->evidence($sales, $a, $first['id'])['body'], 'Authorized download verifies digest and preserves exact bytes.');
rejects(404, fn () => $handovers->evidence($other, $b, $first['id']), 'Evidence ID cannot cross the company scope.');
rejects(404, fn () => $handovers->evidence($oldOwner, $a, $first['id']), 'Former owner cannot download evidence after reassignment.');
$snapshot = $queries->read($sales, $a, 'handovers', $first);
same($owner->userId, $snapshot['acceptance']['recorded_by'], 'System distinguishes actual operator from the external customer contact.');
same(false, array_key_exists('body', $snapshot['acceptance']), 'Details expose metadata without embedding file bytes.');
same($admin->userId, array_values(array_filter($snapshot['timeline'], static fn (array $row): bool => $row['action'] === 'handover.assign'))[0]['actor_id'], 'Reassignment records administrator as actor.');
done('Two-company delivery, reassignment, milestone issues and signed evidence');
