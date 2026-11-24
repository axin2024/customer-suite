<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Database;
use CustomerSuite\DemoWorkspace;
use CustomerSuite\Input;
use CustomerSuite\Operations;

require dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::connect();
$database = $db->row('SELECT DATABASE() AS name')['name'];
if (getenv('CRM_TEST_DATABASE') !== '1' || !preg_match('/^crm_demo_capacity(?:_[a-z0-9_]+)?$/D', $database)) {
    throw new RuntimeException('Use CRM_TEST_DATABASE=1 and an isolated crm_demo_capacity database.');
}
$demo = (new DemoWorkspace($db))->install($argv[1] ?? '', '')['result'];
$manager = new Access($db, $demo['users']['manager']['id']);
$chargedAt = gmdate('Y-m-d H:i:s');
$ops = new Operations($db);
$manifest = $db->transaction(function () use ($db, $manager, $demo, $ops, $chargedAt): array {
    $db->row('SELECT id FROM companies WHERE id = ? FOR UPDATE', [$demo['group']]);
    if ($db->row("SELECT id FROM accounts WHERE registration_ref = 'CAP-AC-A-1' LIMIT 1")) {
        throw new RuntimeException('Capacity data already exists. Use a fresh capacity demo database for a repeat run.');
    }
    $plans = ['a' => 'a-sales', 'c' => 'c-support'];
    $cohorts = [];
    foreach ($plans as $division => $role) {
        $company = $demo['companies'][$division];
        $owner = $demo['users'][$role]['id'];
        $agreement = $demo['contracts'][$division];
        $contract = $db->row('SELECT account_id FROM agreements WHERE company_id = ? AND id = ?', [$company, $agreement]);
        $queue = $ops->execute($manager, $company, 'service.queue', 'capacity-queue-' . $division,
            ['code' => 'CAP', 'name' => '容量验收队列'])['result']['id'];
        $accountPrefix = 'CAP-AC-' . strtoupper($division) . '-';
        $ticketPrefix = 'CAP-TK-' . strtoupper($division) . '-';
        for ($offset = 0; $offset < 5000; $offset += 250) {
            $rows = [];
            for ($index = $offset + 1; $index <= $offset + 250; $index++) {
                $name = $accountPrefix . $index;
                $rows[] = [$company, $owner, $name, $name, $index % 5 === 0 ? 'frozen' : 'active'];
            }
            $db->insertRows('INSERT INTO accounts (company_id,owner_id,name,registration_ref,state)', $rows);
        }
        for ($offset = 0; $offset < 25000; $offset += 250) {
            $rows = [];
            for ($index = $offset + 1; $index <= $offset + 250; $index++) {
                $state = $index % 5 === 0 ? 'waiting' : 'open';
                $rows[] = [$company, $contract['account_id'], $agreement, 1, $queue, $owner, $ticketPrefix . $index,
                    '合成负载记录', 'incident', 'P' . ($index % 4 + 1), $state,
                    $state === 'waiting' ? '等待样例客户' : null, $chargedAt, $chargedAt];
            }
            $db->insertRows('INSERT INTO service_tickets (company_id,account_id,agreement_id,agreement_revision,queue_id,requested_by,
                    title,description,category,priority,state,wait_reason,received_at,last_event_at)', $rows);
        }
        $accounts = $db->row('SELECT COUNT(*) AS n,MAX(id) AS sample_id FROM accounts WHERE company_id = ? AND name LIKE ?',
            [$company, $accountPrefix . '%']);
        $tickets = $db->row('SELECT COUNT(*) AS n,MAX(id) AS sample_id FROM service_tickets WHERE company_id = ? AND title LIKE ?',
            [$company, $ticketPrefix . '%']);
        if ($accounts['n'] !== 5000 || $tickets['n'] !== 25000) {
            throw new RuntimeException('Capacity seed counts are incomplete.');
        }
        $cohorts[$division] = ['company' => $company, 'user' => $demo['users'][$role]['email'], 'queue' => $queue,
            'accounts' => $accounts, 'tickets' => $tickets, 'account_prefix' => $accountPrefix, 'ticket_prefix' => $ticketPrefix];
    }
    return $cohorts;
});
$limits = $db->row('SELECT VERSION() AS mysql,@@innodb_buffer_pool_size AS innodb_buffer_pool_bytes,@@max_connections AS mysql_max_connections');
echo Input::json(['recorded_at' => gmdate(DATE_ATOM), 'sample_counts' => ['customers' => 10000, 'tickets' => 50000],
    'engine' => $limits + ['php' => PHP_VERSION], 'cohorts' => $manifest, 'manager' => $demo['users']['manager']['email'],
    'report_id' => $demo['report_id'], 'handover_id' => $demo['handover']['id']]), PHP_EOL;
