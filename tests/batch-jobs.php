<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\BatchJobs;
use CustomerSuite\Identity;
use CustomerSuite\Operations;
use CustomerSuite\Queries;
use CustomerSuite\Receipts;

require __DIR__ . '/bootstrap.php';

$tag = bin2hex(random_bytes(5));
$identity = new Identity($db);
$password = 'Test-only-' . bin2hex(random_bytes(16));
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
$admin = new Access($db, $root['id'] ?? $identity->bootstrap('系统管理员', "$tag@example.test", $password));
$a = $identity->addCompany($admin, 'BATCH-A-' . $tag, '甲公司批量验收');
$b = $identity->addCompany($admin, 'BATCH-B-' . $tag, '乙公司批量验收');
$staff = static function (int $company, string $role, string $alias) use ($db, $identity, $admin, $tag, $password): Access {
    $id = $identity->addUser($admin, $alias, "$tag.$alias@example.test", $password);
    $identity->membership($admin, $company, $id, $role, true);
    return new Access($db, $id);
};
$manager = $staff($a, 'manager', 'manager');
$market = $staff($a, 'marketing', 'market');
$peer = $staff($a, 'marketing', 'peer');
$seller = $staff($a, 'sales', 'seller');
$sister = $staff($b, 'manager', 'sister');
$ops = new Operations($db);
$queries = new Queries($db);
$jobs = new BatchJobs($db);
$write = static fn (Access $actor, int $company, string $action, array $data, ?string $key = null): array =>
    $ops->execute($actor, $company, $action, $key ?? bin2hex(random_bytes(12)), $data);
$campaign = $write($market, $a, 'campaign.create', ['code' => 'BATCH', 'name' => '线索批量分派演示', 'channel' => 'web'])['result']['id'];
$externalCampaign = $write($sister, $b, 'campaign.create', ['code' => 'BATCH', 'name' => '另一公司', 'channel' => 'web'])['result']['id'];
$intake = static fn (Access $actor, int $company, int $campaign, string $alias): array =>
    array_intersect_key($write($actor, $company, 'lead.receive', ['campaign_id' => $campaign, 'name' => '合成线索 ' . $alias,
        'email' => "$tag.$alias@example.test", 'source' => 'web', 'external_id' => $alias, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z')])['result'],
        ['id' => true, 'version' => true]);
$first = $intake($market, $a, $campaign, 'first');
$second = $intake($market, $a, $campaign, 'second');
$stale = $intake($market, $a, $campaign, 'stale');
$fourth = $intake($market, $a, $campaign, 'fourth');
$external = $intake($sister, $b, $externalCampaign, 'external');
$start = ['kind' => 'lead.reassign', 'items' => [$first, $second, $stale, $fourth, $external],
    'owner_id' => $seller->userId, 'reason' => '分配行业线索给负责销售'];
$id = $write($market, $a, 'batch.create', $start, 'create-batch')['result']['id'];
$compact = array_map(static fn (array $item): string => $item['id'] . ':' . $item['version'], $start['items']);
same(true, $write($market, $a, 'batch.create', array_replace($start, ['items' => $compact]), 'create-batch')['replayed'],
    'Form and API selections share the same create receipt.');
rejects(409, fn () => $write($market, $a, 'batch.create', array_replace($start, ['reason' => '更换原因']), 'create-batch'),
    'Create key cannot change the selected operation.');
rejects(422, fn () => $write($market, $a, 'batch.create', array_replace($start, ['items' => [$first, $first]])),
    'Duplicate subjects are rejected.');
rejects(403, fn () => $write($seller, $a, 'batch.create', $start), 'Sales user cannot launch a market batch.');
rejects(404, fn () => $queries->read($sister, $b, 'batches', ['id' => $id]), 'Another company cannot read the batch.');
rejects(404, fn () => $queries->read($peer, $a, 'batches', ['id' => $id]), 'Peer marketing user cannot read the requester job.');
same([], $queries->read($peer, $a, 'batches', [])['items'], 'Peer job list is empty.');
$view = static fn (Access $actor): array => $queries->read($actor, $a, 'batches', ['id' => $id]);
$before = $view($market);
same('pending', $before['state'], 'Batch preserves pending and rejected selections before a worker runs.');
same(array_column($start['items'], 'id'), array_column($before['items'], 'subject_id'), 'Original selection order is retained.');
same(['pending', 'pending', 'pending', 'pending', 'rejected'], array_column($before['items'], 'state'), 'Wrong-company input never executes.');
same(false, $before['items'][4]['can_open'], 'Rejected input has no source link.');
$candidateIds = array_column($queries->read($market, $a, 'batch-candidates', ['kind' => 'lead.reassign'])['items'], 'id');
same(false, in_array($external['id'], $candidateIds, true), 'Candidate list excludes sibling leads.');
same(1, $jobs->run($manager, $a, 1, $id)['count'], 'First worker commits exactly one item before exiting.');
same(['completed', 'pending', 'pending', 'pending', 'rejected'], array_column($view($market)['items'], 'state'), 'Task resumes from committed marker.');
$write($seller, $a, 'lead.claim', $stale);

$workers = [];
$locked = $before['items'][1]['id'];
$db->transaction(function () use ($db, $manager, $id, $a, $locked, &$workers): void {
    $db->row('SELECT id FROM batch_items WHERE id = ? FOR UPDATE', [$locked]);
    $command = [PHP_BINARY];
    if (getenv('CRM_TEST_PDO_OPTION') === '1') {
        array_push($command, '-d', 'extension=pdo_mysql');
    }
    array_push($command, dirname(__DIR__) . '/bin/crm.php', 'batch-run', (string) $a, (string) $manager->userId, '2', (string) $id);
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start parallel batch workers.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes[1], $pipes[2]];
    }
    $deadline = microtime(true) + 8;
    do {
        $state = $db->row('SELECT state FROM batch_items WHERE company_id = ? AND batch_id = ? AND position = 4', [$a, $id])['state'];
        if ($state === 'completed') {
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    same('completed', $state, 'Workers skip a locked earlier item and commit a later item.');
});
foreach ($workers as [$process, $stdout, $stderr]) {
    $json = stream_get_contents($stdout);
    $errors = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    same(0, proc_close($process), 'Parallel worker exited successfully. ' . $errors);
    same(true, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['count'] <= 2, 'Worker obeys bounded size.');
}
$jobs->run($manager, $a, 10, $id);
$result = $view($market);
same('partial', $result['state'], 'Mixed results have a partial state.');
same(['completed', 'completed', 'rejected', 'completed', 'rejected'], array_column($result['items'], 'state'), 'Only current and scoped leads are assigned.');
same([1, 1, 1, 1, 0], array_column($result['items'], 'attempts'), 'Two workers do not execute any item twice.');
same([1, 1, 1, 1, 1], array_column($result['items'], 'selected_version'), 'Original versions stay searchable.');
same($seller->userId, $queries->read($manager, $a, 'leads', ['id' => $second['id']])['owner_id'], 'Normal lead entry point shows the saved assignment.');
same(0, $jobs->run($manager, $a, 10, $id)['count'], 'An already settled batch may be polled without writes.');
$events = array_values(array_filter($result['timeline'], static fn (array $event): bool => $event['action'] === 'batch.item'));
same(4, count($events), 'One audit record exists for each attempted item.');
same($manager->userId, $events[0]['actor_id'], 'Audit shows actual worker.');
same($market->userId, $events[0]['details']['requested_by'], 'Audit shows whose authority was used.');
same($first['id'], (new Receipts($db))->read($market, $a, $events[0]['details']['receipt_key'])['result']['id'], 'Attempt references its original mutation receipt.');
rejects(409, fn () => $write($market, $a, 'batch.retry', ['id' => $id, 'items' => [array_intersect_key($result['items'][0],
    ['id' => true, 'version' => true])]]), 'Successful items cannot be queued for replay.');

$revoked = $intake($market, $a, $campaign, 'revoked');
$revocationId = $write($market, $a, 'batch.create', array_replace($start, ['items' => [$revoked]]), 'revoked')['result']['id'];
$identity->membership($admin, $a, $market->userId, 'marketing', false);
$jobs->run($manager, $a, 10, $revocationId);
same('rejected', $queries->read($manager, $a, 'batches', ['id' => $revocationId])['state'], 'Revoking requester authority prevents delegated mutations.');
same('open', $queries->read($manager, $a, 'leads', $revoked)['state'], 'Revoked operation leaves source state unchanged.');
rejects(404, fn () => (new Receipts($db))->read($market, $a, 'revoked'), 'Read receipt rechecks current company access.');

done('Resumable batch jobs');
