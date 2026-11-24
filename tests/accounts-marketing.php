<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\BusinessError;
use CustomerSuite\Campaigns;
use CustomerSuite\Contacts;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\LeadIntake;
use CustomerSuite\Leads;
use CustomerSuite\Operations;
use CustomerSuite\Receipts;

require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? '') === 'claim') {
    [$user, $company, $lead, $version] = array_map('intval', array_slice($argv, 2, 4));
    echo "ready\n";
    flush();
    try {
        $result = (new Operations($db))->execute(new Access($db, $user), $company, 'lead.claim', 'race-' . $lead,
            ['id' => $lead, 'version' => $version]);
        echo Input::json(['status' => 200, 'owner' => $result['result']['owner_id']]), PHP_EOL;
    } catch (BusinessError $error) {
        echo Input::json(['status' => $error->status, 'owner' => $error->details['owner']['id'] ?? null]), PHP_EOL;
    }
    exit(0);
}

$run = bin2hex(random_bytes(5));
$identity = new Identity($db);
$password = 'Test-only-' . bin2hex(random_bytes(16));
$firstAdmin = $db->row('SELECT id FROM users WHERE is_admin = 1 LIMIT 1');
$adminId = $firstAdmin ? (int) $firstAdmin['id'] : $identity->bootstrap('测试管理员', $run . '@example.test', $password);
$admin = new Access($db, $adminId);
$staff = static fn (string $name): int => $identity->addUser($admin, $name, $run . '.' . $name . '@example.test', $password);
$salesA = $staff('seller-a');
$salesB = $staff('seller-b');
$marketingId = $staff('marketing');
$managerId = $staff('manager');
$successId = $staff('success');
$north = new Access($db, $salesA);
$otherSeller = new Access($db, $salesB);
$marketing = new Access($db, $marketingId);
$manager = new Access($db, $managerId);
$success = new Access($db, $successId);
$head = $identity->addCompany($admin, 'G-' . $run, '总部');
$branch = $identity->addCompany($admin, 'A-' . $run, '甲公司', $head);
$sister = $identity->addCompany($admin, 'B-' . $run, '乙公司', $head);
$third = $identity->addCompany($admin, 'C-' . $run, '丙公司', $head);
foreach ([[$branch, $salesA, 'sales'], [$branch, $salesB, 'sales'], [$sister, $salesB, 'sales'],
    [$branch, $marketingId, 'marketing'], [$branch, $managerId, 'manager'],
    [$head, $managerId, 'manager'], [$branch, $successId, 'success']] as [$company, $user, $role]) {
    $identity->membership($admin, $company, $user, $role, true);
}
same($salesA, $identity->authenticate($run . '.SELLER-A@example.test', $password)['id'], 'Login normalizes email.');
rejects(401, fn () => $identity->authenticate($run . '.seller-a@example.test', 'bad-password'), 'Invalid login.');
same([$branch], array_column($north->companies(), 'id'), 'Seller can select only assigned company.');
rejects(404, fn () => $north->company($sister), 'Sibling company is invisible.');
rejects(404, fn () => $manager->company($third), 'Group relationship grants no implicit membership.');

$accounts = new Accounts($db);
$contacts = new Contacts($db);
$parent = $accounts->create($manager, $head, ['name' => '演示控股集团']);
$canonical = $accounts->create($manager, $branch, ['name' => '精密设备甲', 'registration_ref' => 'EX-A', 'owner_id' => $salesA,
    'parent_id' => $parent['id'], 'parent_company_id' => $head]);
$identicalName = $accounts->create($manager, $branch, ['name' => '精密设备甲', 'registration_ref' => 'EX-DIFFERENT', 'owner_id' => $salesB]);
$duplicate = $accounts->create($manager, $branch, ['name' => '精密设备甲（历史档案）', 'registration_ref' => 'ex-a', 'owner_id' => $salesB]);
$siblingAccount = $accounts->create($admin, $sister, ['name' => '精密设备乙', 'owner_id' => $salesB,
    'parent_id' => $parent['id'], 'parent_company_id' => $head]);
$thirdAccount = $accounts->create($admin, $third, ['name' => '精密设备丙', 'parent_id' => $parent['id'], 'parent_company_id' => $head]);
same($parent['id'], $accounts->find($north, $branch, $canonical['id'])['parent_id'], 'Group linked without broadening read scope.');
same($head, $accounts->find($admin, $third, $thirdAccount['id'])['parent_company_id'], 'Third branch keeps its own account.');
rejects(404, fn () => $accounts->find($north, $head, $parent['id']), 'Seller cannot read group account.');
rejects(404, fn () => $accounts->find($north, $branch, $siblingAccount['id']), 'Wrong-company object ID cannot resolve.');
rejects(404, fn () => $accounts->find($otherSeller, $branch, $canonical['id']), 'Peer sales owner cannot read the account.');
rejects(403, fn () => $accounts->writable($otherSeller, $branch, $canonical['id']), 'Peer sales owner cannot update the account.');
$error = rejects(409, fn () => $accounts->merge($manager, $branch, $canonical['id'], 1, $identicalName['id'], 1), 'AC-02 Same name with different registration.');
same(true, str_contains($error->getMessage(), '识别证据不足'), 'AC-02 explains identity evidence.');
$duplicateVersion = $accounts->addAlias($otherSeller, $branch, $duplicate['id'], 1, 'legacy', 'company-110')['version'];
rejects(409, fn () => $accounts->addAlias($north, $branch, $canonical['id'], 1, 'legacy', 'company-110'), 'External aliases unique in a company.');
$contact = $contacts->save($otherSeller, $branch, $duplicate['id'], [
    'name' => '乔样例', 'job_title' => '采购主管', 'email' => 'Qiao@Example.test', 'phone' => '00 86 139 1234 5678',
]);
same([], $contacts->directory($otherSeller, $branch, $duplicate['id'], 'email'), 'Exports require explicit consent.');
$permission = ['purpose' => 'export', 'channel' => 'email', 'allowed' => true, 'evidence' => 'FORM-110'];
$contacts->permission($otherSeller, $branch, $duplicate['id'], $contact['id'], 1, $permission);
same('qiao@example.test', $contacts->directory($otherSeller, $branch, $duplicate['id'], 'email')[0]['address'], 'Email export scoped by purpose and channel.');
same([], $contacts->directory($otherSeller, $branch, $duplicate['id'], 'phone'), 'Email consent does not permit phone export.');
$accounts->merge($manager, $branch, $canonical['id'], 1, $duplicate['id'], $duplicateVersion);
$details = $accounts->detail($north, $branch, $canonical['id']);
same($duplicate['id'], $details['aliases'][0]['original_account_id'], 'Alias remembers previous record and name.');
same($salesB, $details['aliases'][0]['original_owner_id'], 'Historical sales owner retained.');
same('account.merge.accept', $details['timeline'][1]['action'], 'Merge audited on destination.');
same('merged', $accounts->find($manager, $branch, $duplicate['id'])['state'], 'Source persists for historic objects.');
same($duplicate['id'], $contacts->forAccount($north, $branch, $canonical['id'])[0]['original_account_id'], 'Contact keeps its original customer.');
same('+8613912345678', $contacts->forAccount($north, $branch, $canonical['id'])[0]['phone'], 'Telephone normalization.');
same(false, array_key_exists('email', $contacts->forAccount($success, $branch, $canonical['id'])[0]), 'Customer success list redacts contact channels.');
$contactVersion = $contacts->forAccount($north, $branch, $canonical['id'])[0]['version'];
$contacts->permission($north, $branch, $canonical['id'], $contact['id'], $contactVersion,
    array_replace($permission, ['allowed' => false, 'evidence' => 'WITHDRAWAL-110']));
same([], $contacts->directory($north, $branch, $canonical['id'], 'email'), 'Withdrawal takes effect in the next export.');
rejects(404, fn () => $contacts->directory($north, $sister, $siblingAccount['id'], 'email'), 'Cross-company export denied.');

$campaigns = new Campaigns($db);
$web = $campaigns->create($marketing, $branch, ['code' => 'FORM', 'name' => '官网报名', 'channel' => 'web']);
$expo = $campaigns->create($marketing, $branch, ['code' => 'EXPO', 'name' => '线下展会', 'channel' => 'event']);
$outOfScope = $campaigns->create($admin, $sister, ['code' => 'FORM', 'name' => '乙公司官网', 'channel' => 'web']);
$intake = new LeadIntake($db);
$leads = new Leads($db);
$operations = new Operations($db);
$base = ['campaign_id' => $web['id'], 'name' => '样例线索', 'email' => 'Person@Example.test', 'phone' => '00 86 138 3333 2222',
    'source' => 'web-form', 'external_id' => 'signup/7', 'occurred_at' => '2026-11-23T09:00:00+08:00'];
$first = $operations->execute($marketing, $branch, 'lead.receive', 'first-import', $base)['result'];
same('created', $first['match'], 'First import creates lead.');
$second = $intake->receive($marketing, $branch, array_replace($base, [
    'campaign_id' => $expo['id'], 'source' => 'tradeshow', 'external_id' => 'signup/7',
    'email' => 'person@example.test', 'phone' => '+86 (138) 3333 2222', 'occurred_at' => '2026-11-22T01:00:00Z',
]));
same($first['id'], $second['id'], 'AC-03 Both campaigns match the same normalized identity.');
same('matched', $second['match'], 'Second import records matching.');
$lead = $leads->detail($marketing, $branch, $first['id']);
same(2, count($lead['touches']), 'Two touchpoints persist.');
same($expo['id'], $lead['first_touch']['campaign_id'], 'First touch uses event time rather than import order.');
same($web['id'], $lead['recent_touch']['campaign_id'], 'Recent touch uses event time.');
same('•••2222', $lead['phone'], 'Marketing does not read full phone from unassigned record.');
same('•••@example.test', $lead['email'], 'Marketing does not read full email from unassigned record.');
same($first['touch_id'], $intake->receive($marketing, $branch, $base)['touch_id'], 'Same source event reuses touch.');
rejects(409, fn () => $intake->receive($marketing, $branch, array_replace($base, ['name' => '其他内容'])), 'Same source event with different content rejected.');
rejects(404, fn () => $intake->receive($marketing, $branch, array_replace($base, [
    'campaign_id' => $outOfScope['id'], 'external_id' => 'foreign-1',
])), 'Another company campaign cannot be attached.');
$sameReceipt = $operations->execute($marketing, $branch, 'lead.receive', 'first-import',
    array_replace($base, ['campaign_id' => (string) $web['id']]));
same(true, $sameReceipt['replayed'], 'Form and JSON numbers normalize to same operation receipt.');
same(Input::digest($first), Input::digest($sameReceipt['result']), 'Retry returns original version and touch.');
$receipt = (new Receipts($db))->read($marketing, $branch, 'first-import');
same($first['id'], $receipt['result']['id'], 'Receipt query recovers original business ID.');
rejects(404, fn () => (new Receipts($db))->read($manager, $branch, 'first-import'), 'Another actor cannot access receipt.');
rejects(409, fn () => $operations->execute($marketing, $branch, 'lead.receive', 'first-import',
    array_replace($base, ['name' => '冲突'])), 'Request key cannot be reused with different body.');

$processes = [];
$db->transaction(function () use ($db, $branch, $first, $second, $salesA, $salesB, &$processes): void {
    $db->row('SELECT id FROM leads WHERE company_id = ? AND id = ? FOR UPDATE', [$branch, $first['id']]);
    foreach ([$salesA, $salesB] as $user) {
        $command = [PHP_BINARY];
        if (getenv('CRM_TEST_PDO_OPTION') === '1') {
            array_push($command, '-d', 'extension=pdo_mysql');
        }
        array_push($command, __FILE__, 'claim', (string) $user, (string) $branch, (string) $first['id'], (string) $second['version']);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a separate sales request.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes[1], $pipes[2]];
        same('ready', trim((string) fgets($pipes[1])), 'Concurrent claimant is connected.');
    }
});
$outcomes = [];
foreach ($processes as [$process, $stdout, $stderr]) {
    $output = stream_get_contents($stdout);
    $errors = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    same(0, proc_close($process), 'Claim request process ran successfully. ' . $errors);
    $outcomes[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
usort($outcomes, static fn (array $a, array $b): int => $a['status'] <=> $b['status']);
same([200, 409], array_column($outcomes, 'status'), 'AC-04 Concurrent claiming has one winner and one conflict.');
same($outcomes[0]['owner'], $outcomes[1]['owner'], 'AC-04 Conflict identifies current owner.');
same(1, (int) $db->row("SELECT COUNT(*) AS n FROM audit_events WHERE company_id = ? AND object_id = ? AND action = 'lead.claim'",
    [$branch, $first['id']])['n'], 'Only one durable claim.');
$ownerId = $outcomes[0]['owner'];
$ownerActor = new Access($db, $ownerId);
$loser = $ownerId === $salesA ? $otherSeller : $north;
$claimed = $leads->detail($ownerActor, $branch, $first['id']);
same('+8613833332222', $claimed['phone'], 'Assigned owner can see the full normalized phone.');
rejects(404, fn () => $leads->detail($loser, $branch, $first['id']), 'Non-owner does not see claimed lead.');
$reopened = $leads->reassign($marketing, $branch, $first['id'], $claimed['version'], null, '负责人离岗');
same('open', $reopened['state'], 'Marketing can reclaim to the pool.');
same(null, $reopened['owner_id'], 'Reclaimed lead has no active owner.');
rejects(403, fn () => $leads->reassign($north, $branch, $first['id'], $reopened['version'], $salesA, '抢占'), 'Sales cannot assign other users.');
$assigned = $leads->reassign($marketing, $branch, $first['id'], $reopened['version'], $salesA, '华北负责');
$qualified = $leads->qualify($north, $branch, $first['id'], $assigned['version'], $canonical['id'], '2026-11-30');
same($canonical['id'], $qualified['account_id'], 'Owned lead advances to a scoped qualified customer.');
same('qualified', $leads->detail($north, $branch, $first['id'])['state'], 'Qualification persists.');
same(1, count($leads->list($north, $branch, 'qualified')['items']), 'Qualified worklist for current owner.');
same([], $leads->list($otherSeller, $branch, 'qualified')['items'], 'Qualified worklist filtered by owner.');

$authVersion = $otherSeller->user()['auth_version'];
$identity->membership($admin, $branch, $salesB, 'sales', false);
same($authVersion + 1, $otherSeller->user()['auth_version'], 'Revocation invalidates existing web sessions.');
rejects(404, fn () => $otherSeller->company($branch), 'Revocation removes company visibility.');
same($sister, $otherSeller->company($sister)['id'], 'Other membership survives company-specific revocation.');
done('Customer accounts, contacts, marketing and lead race');
