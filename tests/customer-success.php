<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\BatchJobs;
use CustomerSuite\Contacts;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Outreach;
use CustomerSuite\Queries;
use CustomerSuite\Receipts;

require __DIR__ . '/bootstrap.php';
putenv('CRM_OUTREACH_MODE=replay');

if (($argv[1] ?? null) === 'scan') {
    $actor = new Access($db, (int) $argv[2]);
    echo "ready\n";
    fflush(STDOUT);
    $result = (new Operations($db))->execute($actor, (int) $argv[3], 'renewal.scan', $argv[4], ['as_of' => $argv[5]]);
    echo Input::json($result['result']);
    exit(0);
}

$tag = bin2hex(random_bytes(5));
$identity = new Identity($db);
$password = 'Test-only-' . bin2hex(random_bytes(16));
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
$admin = new Access($db, $root['id'] ?? $identity->bootstrap('系统管理员', $tag . '@example.test', $password));
$a = $identity->addCompany($admin, 'SUCCESS-A-' . $tag, '甲客户成功公司');
$b = $identity->addCompany($admin, 'SUCCESS-B-' . $tag, '乙客户成功公司');
$newActor = static function (int $company, string $role, string $alias) use ($identity, $admin, $db, $tag, $password): Access {
    $id = $identity->addUser($admin, '合成岗位 ' . $alias, "$tag.$alias@example.test", $password);
    $identity->membership($admin, $company, $id, $role, true);
    return new Access($db, $id);
};
$seller = $newActor($a, 'sales', 'seller');
$manager = $newActor($a, 'manager', 'manager');
$owner = $newActor($a, 'success', 'owner');
$replacement = $newActor($a, 'success', 'replacement');
$sister = $newActor($b, 'success', 'sister');
$ops = new Operations($db);
$queries = new Queries($db);
$write = static fn (Access $actor, string $action, array $data, ?string $key = null): array =>
    $ops->execute($actor, $a, $action, $key ?? bin2hex(random_bytes(12)), $data)['result'];
$clock = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$date = static fn (int $days): string => $clock->modify(sprintf('%+d days', $days))->format('Y-m-d');
$accounts = new Accounts($db);
$contacts = new Contacts($db);
$account = $accounts->create($seller, $a, ['name' => '客户成功验收主体']);
$contact = $contacts->save($seller, $a, $account['id'], ['name' => '合成采购联系人', 'job_title' => '采购',
    'email' => 'consent@example.test', 'phone' => '+8613800000001']);
$permission = ['purpose' => 'success', 'channel' => 'email', 'allowed' => true, 'evidence' => 'RENEWAL-OPT-IN'];
$contacts->permission($seller, $a, $account['id'], $contact['id'], 1, $permission);

$opportunity = $write($seller, 'opportunity.create', ['account_id' => $account['id'], 'title' => '客户托管服务',
    'currency' => 'CNY', 'amount' => '2100', 'expected_close' => $date(4)])['id'];
$opp = ['id' => $opportunity];
$write($seller, 'opportunity.move', $opp + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $contact['id']]);
$write($seller, 'opportunity.products', $opp + ['version' => 2, 'products' => [[
    'product_code' => 'SUPPORT', 'product_version' => '1', 'description' => '托管服务', 'quantity' => 1, 'unit_price' => '2100',
]]]);
$write($seller, 'opportunity.move', $opp + ['version' => 3, 'stage' => 'proposal']);
$quote = $write($seller, 'quote.prepare', ['opportunity_id' => $opportunity, 'opportunity_version' => 4, 'discount_bps' => 0,
    'expires_on' => $date(4), 'terms' => '三十天内到期的合成服务合同']);
$write($seller, 'quote.submit', ['id' => $quote['id'], 'version' => 1]);
$write($seller, 'agreement.sign', ['id' => $quote['id'], 'version' => 2, 'scope' => 'single', 'allocations' => [[
    'company_id' => $a, 'account_id' => $account['id'], 'reference_no' => 'SUCCESS-1', 'amount' => '2100',
    'starts_on' => $date(-60), 'ends_on' => $date(12), 'service_level' => 'standard', 'evidence_ref' => 'SUCCESS-SIGNED',
]]]);
$agreement = $db->row('SELECT id FROM agreements WHERE company_id = ? AND quote_id = ?', [$a, $quote['id']])['id'];
$target = ['id' => $agreement];
$assign = ['agreement_id' => $agreement, 'agreement_version' => 1, 'reason' => '安装后完成初次分配'];
same(0, $write($manager, 'renewal.scan', ['as_of' => $date(0)])['created_count'], 'No renewal with no assigned success owner.');
rejects(422, fn () => $write($manager, 'success.assign', $assign + ['owner_id' => $sister->userId]), 'Cannot assign sibling employee.');
same(1, $write($manager, 'success.assign', $assign + ['owner_id' => $owner->userId])['version'], 'Initial assignment has an optimistic version.');
rejects(409, fn () => $write($manager, 'success.assign', $assign + ['owner_id' => $replacement->userId]), 'Stale assignment cannot overwrite owner.');
rejects(403, fn () => $write($owner, 'renewal.scan', []), 'Success staff cannot launch company-wide scans.');
same([], $queries->read($replacement, $a, 'success-contracts', [])['items'], 'Unassigned contract does not appear in list.');
rejects(404, fn () => $queries->read($replacement, $a, 'success-contracts', $target), 'Unassigned contract inaccessible by ID.');
rejects(404, fn () => $queries->read($sister, $a, 'success-contracts', $target), 'Sibling does not have company visibility.');

$workers = [];
$db->transaction(function () use ($db, $a, $account, $manager, $tag, $date, &$workers): void {
    $db->row('SELECT id FROM accounts WHERE company_id = ? AND id = ? FOR UPDATE', [$a, $account['id']]);
    foreach (['a', 'b'] as $runner) {
        $command = [PHP_BINARY];
        if (getenv('CRM_TEST_PDO_OPTION') === '1') {
            array_push($command, '-d', 'extension=pdo_mysql');
        }
        array_push($command, __FILE__, 'scan', (string) $manager->userId, (string) $a, "$tag-race-$runner", $date(0));
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start parallel renewal scan.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes[1], $pipes[2]];
        same('ready', trim((string) fgets($pipes[1])), 'Independent scanner can connect during the held account lock.');
    }
});
$results = [];
foreach ($workers as [$process, $stdout, $stderr]) {
    $json = stream_get_contents($stdout);
    $errors = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    same(0, proc_close($process), 'Renewal scan completed. ' . $errors);
    $results[] = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
}
$created = array_merge(...array_column($results, 'items'));
same(1, count($created), 'AC-13 Two concurrent scans create one renewal and opportunity.');
$renewal = ['id' => $created[0]['id']];
$opp = ['id' => $created[0]['opportunity_id']];
same(0, $write($manager, 'renewal.scan', ['as_of' => $date(0)])['created_count'], 'AC-13 A third scan makes no changes.');
same(1, $db->row('SELECT COUNT(*) AS n FROM opportunities WHERE company_id = ? AND account_id = ? AND title LIKE ?',
    [$a, $account['id'], '续约 · %'])['n'], 'There are no orphan duplicate opportunities.');
same(210000, $queries->read($seller, $a, 'opportunities', $opp)['value_minor'], 'Renewal opportunity uses contract currency and value.');
rejects(409, fn () => $write($seller, 'opportunity.move', $opp + ['version' => 1, 'stage' => 'lost', 'reason' => '跳过续约台']),
    'Sales cannot bypass the renewal outcome.');

$health = $write($owner, 'success.health', ['agreement_id' => $agreement], 'original-health');
same('healthy', $health['band'], 'An active agreement with no open issues retains its score.');
$renewalVersion = $renewal + ['version' => 1];
$job = $write($owner, 'outreach.queue', $renewalVersion + ['contact_id' => $contact['id'], 'channel' => 'email', 'message' => '请复核续约材料。']);
same('queued', $job['state'], 'Current consent allows enqueue.');
$duplicate = $write($owner, 'outreach.queue', $renewalVersion + ['contact_id' => $contact['id'], 'channel' => 'email', 'message' => '请复核续约材料。']);
same($job['id'], $duplicate['id'], 'Queue de-duplicates equal intent across HTTP request IDs.');
same(true, $duplicate['reused'], 'Queue marks reused delivery record.');
$frozen = $write($manager, 'account.revise', ['id' => $account['id'], 'version' => 1, 'state' => 'frozen']);
same('frozen', $queries->read($owner, $a, 'success-contracts', $target)['account_state'], 'AC-14 Freeze is visible before the next score capture.');
$risk = $write($owner, 'success.health', ['agreement_id' => $agreement]);
same('risk', $risk['band'], 'AC-14 Frozen account is classified as risk.');
same(0, $risk['score'], 'Freeze is an explicit zero-score signal.');
same('frozen', $queries->read($owner, $a, 'health', ['id' => $risk['id']])['signals'][0]['state'], 'Snapshot preserves the frozen source version.');
same('healthy', $queries->read($owner, $a, 'health', ['id' => $health['id']])['band'], 'Old snapshot remains unchanged.');
$blocked = (new Outreach($db))->processDue($manager, $a)['items'][0];
same('account_frozen', $blocked['block_reason'], 'AC-14 Queued job is blocked at dispatch after freeze.');
same(0, $blocked['attempts'], 'AC-14 No provider attempt is made while frozen.');
same(0, (new Outreach($db))->processDue($manager, $a)['count'], 'Automatic runner leaves blocked items on hold.');
$write($manager, 'account.revise', ['id' => $account['id'], 'version' => $frozen['version'], 'state' => 'active']);
$consentGone = $contacts->permission($seller, $a, $account['id'], $contact['id'], 2, array_replace($permission, ['allowed' => false]));
$outreachKey = ['id' => $job['id'], 'version' => $blocked['version']];
$withdrawn = $write($owner, 'outreach.process', $outreachKey);
same('consent_missing', $withdrawn['block_reason'], 'Thaw does not bypass a contact withdrawal.');
same(0, $withdrawn['attempts'], 'Revoked permission prevents provider invocation.');
$contacts->permission($seller, $a, $account['id'], $contact['id'], $consentGone['version'], $permission);
same('contact_changed', $write($owner, 'outreach.process', ['id' => $job['id'], 'version' => $withdrawn['version']])['block_reason'],
    'Authorization change requires reconsidering the old intent.');
$fresh = $write($owner, 'outreach.queue', $renewalVersion + ['contact_id' => $contact['id'], 'channel' => 'email',
    'message' => '请复核续约材料。'], 'owner-outreach');
$processed = $write($owner, 'outreach.process', ['id' => $fresh['id'], 'version' => $fresh['version']], 'owner-process');
same('replayed', $processed['state'], 'Default gateway validates a synthetic dispatch without sending.');
same(1, $processed['attempts'], 'Synthetic dispatch is recorded once.');
same(true, $ops->execute($owner, $a, 'outreach.process', 'owner-process', ['id' => $fresh['id'], 'version' => $fresh['version']])['replayed'],
    'Recovering dispatch receipt does not call gateway again.');
$items = $queries->read($owner, $a, 'outreach', $renewal)['items'];
same(false, str_contains(Input::json($items), 'consent@example.test'), 'Worklist contains no destination addresses.');
$outreachAttempt = ['id' => $fresh['id'], 'version' => $processed['version']];
rejects(409, fn () => $write($owner, 'outreach.process', $outreachAttempt), 'A new request cannot submit a completed dispatch.');

putenv('CRM_OUTREACH_MODE=webhook');
putenv('CRM_OUTREACH_WEBHOOK');
putenv('CRM_OUTREACH_SECRET');
$external = $write($owner, 'outreach.queue', $renewalVersion + ['contact_id' => $contact['id'], 'channel' => 'email',
    'message' => '预查自托管渠道配置。']);
$failed = (new Outreach($db))->processDue($manager, $a)['items'][0];
same($external['id'], $failed['id'], 'Runner handles staged external job.');
same('retryable', $failed['state'], 'Unavailable configuration produces an actionable retry state.');
same('gateway_configuration', $failed['last_error'], 'Configuration failure is distinct from gateway timeout.');
same(0, (new Outreach($db))->processDue($manager, $a)['count'], 'Retry backoff prevents an immediate loop.');
putenv('CRM_OUTREACH_MODE=replay');
$stillExternal = $write($owner, 'outreach.process', ['id' => $external['id'], 'version' => $failed['version']]);
same('retryable', $stillExternal['state'], 'Changing the environment cannot turn a real dispatch into a replay.');
$cancelled = $write($owner, 'outreach.cancel', ['id' => $external['id'], 'version' => $stillExternal['version'], 'reason' => '暂停此渠道切换']);
same('cancelled', $cancelled['state'], 'Cancelling a retryable task leaves its history intact.');

$batchQueue = static fn (string $message): array => array_intersect_key($write($owner, 'outreach.queue',
    $renewalVersion + ['contact_id' => $contact['id'], 'channel' => 'email', 'message' => $message]), ['id' => true, 'version' => true]);
$localBatch = $batchQueue('可回放的例行续约检查。');
putenv('CRM_OUTREACH_MODE=webhook');
$remoteOne = $batchQueue('渠道故障时的第一条。');
$remoteTwo = $batchQueue('渠道故障时的第二条。');
$queued = $write($owner, 'batch.create', ['kind' => 'outreach.process', 'items' => [$localBatch, $remoteOne, $remoteTwo]]);
$batchId = ['id' => $queued['id']];
$batchWork = new BatchJobs($db);
same(0, (new Outreach($db))->processDue($manager, $a)['count'], 'Generic scheduler leaves selected batch dispatches to batch workers.');
putenv('CRM_OUTREACH_MODE=replay');
same(1, $batchWork->run($manager, $a, 1, $queued['id'])['count'], 'Replay item is handled on its own.');
putenv('CRM_OUTREACH_MODE=webhook');
same(2, $batchWork->run($manager, $a, 10, $queued['id'])['count'], 'Later worker resumes the same outreach batch.');
$beforeRetry = $queries->read($owner, $a, 'batches', $batchId);
same(['completed', 'retryable', 'retryable'], array_column($beforeRetry['items'], 'state'), 'Configuration failure is stored per selected item.');
same('partial', $beforeRetry['state'], 'Mixed dispatch outcomes remain visible.');
same($renewal['id'], $beforeRetry['items'][0]['renewal_id'], 'Completed dispatch can return to the source renewal.');
$selectedRetry = array_intersect_key($beforeRetry['items'][1], ['id' => true, 'version' => true]);
$retryData = $batchId + ['items' => [$selectedRetry]];
$write($owner, 'batch.retry', $retryData, 'batch-outreach-retry');
same(true, $ops->execute($owner, $a, 'batch.retry', 'batch-outreach-retry', $retryData)['replayed'], 'Retry command can recover its original receipt.');
rejects(409, fn () => $write($owner, 'batch.retry', $retryData), 'Stale retry selection cannot requeue again.');
same(['completed', 'pending', 'retryable'], array_column($queries->read($owner, $a, 'batches', $batchId)['items'], 'state'),
    'Retry queues only the requested failed item.');
$idempotency = static fn (): string => $db->row('SELECT idempotency_key FROM outreach_jobs WHERE company_id = ? AND id = ?', [$a, $remoteOne['id']])['idempotency_key'];
$oldKey = $idempotency();
$portProbe = stream_socket_server('tcp://127.0.0.1:0');
if ($portProbe === false) {
    throw new RuntimeException('Unable to reserve a loopback test port.');
}
$loopback = stream_socket_get_name($portProbe, false);
fclose($portProbe);
putenv('CRM_OUTREACH_WEBHOOK=https://' . $loopback . '/batch-probe');
putenv('CRM_OUTREACH_SECRET=' . bin2hex(random_bytes(24)));
same(1, $batchWork->run($manager, $a, 10, $queued['id'])['count'], 'Only one item is retried.');
$waitingBatch = $queries->read($owner, $a, 'batches', $batchId);
same(['completed', 'waiting_external', 'retryable'], array_column($waitingBatch['items'], 'state'), 'Unknown gateway response is distinct from bad configuration.');
same([1, 2, 1], array_column($waitingBatch['items'], 'attempts'), 'Completed and untouched items keep their prior counts.');
same($oldKey, $idempotency(), 'Gateway retry preserves the immutable submission key.');
same(false, str_contains(Input::json($waitingBatch), 'consent@example.test'), 'Batch records do not contain the delivery address.');
putenv('CRM_OUTREACH_MODE=replay');
putenv('CRM_OUTREACH_WEBHOOK');
putenv('CRM_OUTREACH_SECRET');

$working = $write($owner, 'renewal.follow-up', $renewalVersion + ['next_follow_up' => $date(1), 'reason' => '安排客户预算会']);
same('working', $working['state'], 'Assigned owner sets follow-up.');
$write($manager, 'success.assign', array_replace($assign, ['assignment_version' => 1, 'owner_id' => $replacement->userId]));
rejects(404, fn () => $queries->read($owner, $a, 'renewals', $renewal), 'Reassigned former owner cannot read renewal.');
rejects(404, fn () => (new Receipts($db))->read($owner, $a, 'original-health'), 'Reassigned former owner cannot read old health receipt.');
rejects(404, fn () => (new Receipts($db))->read($owner, $a, 'owner-outreach'), 'Reassigned former owner cannot read old outreach receipt.');
$hiddenBatch = $queries->read($owner, $a, 'batches', $batchId);
same([false, false, false], array_column($hiddenBatch['items'], 'can_open'), 'Former owner retains submitted batch history with no source links.');
same([null, null, null], array_column($hiddenBatch['items'], 'result'), 'Former owner no longer sees dispatch receipts or renewal identifiers.');
rejects(404, fn () => $write($owner, 'batch.retry', $batchId + ['items' => [array_intersect_key($hiddenBatch['items'][1],
    ['id' => true, 'version' => true])]]), 'Former owner cannot retry a reassigned renewal.');
$newVersion = $queries->read($replacement, $a, 'renewals', $renewal)['version'];
same($working['version'] + 1, $newVersion, 'Version advances on owner handoff.');
$lose = $renewal + ['version' => $newVersion, 'outcome' => 'lost', 'code' => 'price', 'reason' => '客户年度预算未通过'];
same('lost', $write($replacement, 'renewal.decide', $lose)['state'], 'New owner can record reason for loss.');
same('lost', $queries->read($seller, $a, 'opportunities', $opp)['stage'], 'Renewal decision closes linked sales opportunity.');
rejects(409, fn () => $write($replacement, 'renewal.decide', $lose), 'A second outcome cannot overwrite the first.');

$amend = static function (int $version, int $end) use ($write, $seller, $manager, $agreement, $date): void {
    $write($seller, 'agreement.propose', ['id' => $agreement, 'version' => $version, 'amount' => '2100', 'starts_on' => $date(-60),
        'ends_on' => $date($end), 'service_level' => 'standard', 'terms' => '补充协议归档', 'reason' => '与客户确认合同时点']);
    $write($manager, 'agreement.decide', ['id' => $agreement, 'version' => $version + 1, 'decision' => 'signed', 'reason' => '已核对签署',
        'evidence_ref' => 'SIGNED-' . $version]);
};
$amend(1, 12);
same(0, $write($manager, 'renewal.scan', [])['created_count'], 'Revising a contract without changing expiry does not duplicate the cycle.');
$amend(3, 22);
$otherRenewal = $write($manager, 'renewal.scan', [])['items'][0];
same($date(22), $otherRenewal['cycle_ends_on'], 'Changed expiry creates a separate cycle.');
$amend(5, 28);
$current = $write($manager, 'renewal.scan', [])['items'][0];
same('superseded', $queries->read($replacement, $a, 'renewals', ['id' => $otherRenewal['id']])['state'], 'Open cycle is superseded when replaced.');
$success = ['id' => $current['id'], 'version' => 1, 'outcome' => 'renewed', 'code' => 'signed', 'reason' => '接受续约报价'];
rejects(422, fn () => $write($replacement, 'renewal.decide', $success), 'Renewal success requires a signed-evidence reference.');
same('renewed', $write($replacement, 'renewal.decide', $success + ['evidence_ref' => 'SIGNED-RENEWED-1'])['state'], 'Signed outcome is persisted.');
same('won', $queries->read($seller, $a, 'opportunities', ['id' => $current['opportunity_id']])['stage'], 'Signed outcome records a won sales stage.');
same('SIGNED-RENEWED-1', $queries->read($replacement, $a, 'renewals', ['id' => $current['id']])['evidence_ref'], 'Readback preserves evidence reference.');
done('Customer success, health, renewal race and outreach');
