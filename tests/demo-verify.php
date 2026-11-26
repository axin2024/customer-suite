<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\BusinessError;
use CustomerSuite\Database;
use CustomerSuite\DemoWorkspace;
use CustomerSuite\Handovers;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Queries;
use CustomerSuite\Reports;

require dirname(__DIR__) . '/src/bootstrap.php';

if (getenv('CRM_TEST_DATABASE') !== '1') {
    throw new RuntimeException('Set CRM_TEST_DATABASE=1 and point CRM_DSN at the isolated pre-installed demo.');
}
$db = Database::connect();
$demo = new DemoWorkspace($db);
$install = $demo->install($argv[1] ?? '', '');
$data = $install['result'];
$checks = 0;
$verify = static function (bool $matches, string $message) use (&$checks): void {
    if (!$matches) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$missing = static function (callable $read, string $message) use ($verify): void {
    try {
        $read();
    } catch (BusinessError $error) {
        $verify($error->status === 404, $message);
        return;
    }
    throw new RuntimeException($message);
};
$verify($install['replayed'], 'The repeat install should recover the receipt.');
$verify(Input::digest($data) === Input::digest($demo->install($argv[1] ?? '', '')['result']), 'Manifest should be durable.');
$verify($db->row('SELECT COUNT(*) AS n FROM companies')['n'] === 4, 'Exactly the group and three subsidiaries are installed.');
$verify($db->row('SELECT COUNT(*) AS n FROM users')['n'] === 6, 'Repeated installation adds no accounts.');
$identity = new Identity($db);
$access = [];
foreach ($data['users'] as $role => $record) {
    $login = $identity->authenticate($record['email'], (string) getenv('CRM_DEMO_PASSWORD'));
    $verify($login['id'] === $record['id'], 'Demo account can sign in: ' . $role);
    $access[$role] = new Access($db, $login['id']);
}
['a-sales' => $seller, 'a-delivery' => $delivery, 'b-success' => $success, 'c-support' => $support, 'manager' => $manager] = $access;
['a' => $a, 'b' => $b, 'c' => $c] = $data['companies'];
$read = new Queries($db);
foreach (['a' => $seller, 'b' => $success, 'c' => $support] as $division => $actor) {
    $company = $data['companies'][$division];
    $legal = $read->read($actor, $company, 'agreements', ['id' => $data['contracts'][$division]]);
    $verify($legal['reference_no'] === 'DEMO-AGREEMENT-' . strtoupper($division), 'Each branch has its own signed contract.');
    foreach (array_diff_key($data['contracts'], [$division => true]) as $foreign) {
        $missing(fn () => $read->read($actor, $company, 'agreements', ['id' => $foreign]), 'Sibling contract must be invisible.');
    }
}
$lead = $read->read($seller, $a, 'leads', ['id' => $data['sales']['lead_id']]);
$verify(count($lead['touches']) === 2 && $lead['state'] === 'converted', 'Both activity touches are saved on the converted lead.');
$forecast = $read->read($manager, $a, 'forecasts', ['id' => $data['sales']['forecast_id']])['items'][0];
$verify($forecast['value_minor'] === 100000000 && $forecast['probability_bps'] === 6000
    && $forecast['weighted_minor'] === 60000000, 'Saved forecast must total 600,000 CNY.');
$record = ['id' => $data['handover']['id']];
$archived = $read->read($delivery, $a, 'handovers', $record);
$blob = (new Handovers($db))->evidence($delivery, $a, $record['id']);
$verify($archived['state'] === 'accepted' && hash('sha256', $blob['body']) === $data['handover']['sha256'], 'A handover evidence has the recorded digest.');
$other = $read->read($success, $b, 'handovers', ['id' => $data['b_handover_id']]);
$verify($other['state'] === 'planning', 'B still has an independent handover.');
$renewals = $read->read($success, $b, 'renewals', [])['items'];
$verify(count($renewals) === 1 && $renewals[0]['id'] === $data['renewal']['id'], 'One renewal belongs to B.');
$ops = new Operations($db);
$installDay = (new DateTimeImmutable($renewals[0]['cycle_ends_on']))->modify('-14 days')->format('Y-m-d');
$rescan = $ops->execute($manager, $b, 'renewal.scan', 'demo-verified-rescan-' . gmdate('Ymd'),
    ['as_of' => $installDay])['result'];
$verify($rescan['created_count'] === 0, 'Repeat scan must preserve the existing cycle.');
$outreach = $read->read($success, $b, 'outreach', ['id' => $data['renewal']['id']])['items'];
$verify(count($outreach) === 1 && $outreach[0]['state'] === 'replayed', 'Outreach uses the synthetic replay gateway.');
$ticket = $read->read($support, $c, 'tickets', ['id' => $data['tickets']['id']]);
$arrival = new DateTimeImmutable($data['tickets']['occurred_at']);
$deadline = $arrival->modify('next monday')->setTime(9, 20)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
$verify($arrival->format('N H:i') === '5 17:50' && $ticket['clocks']['response']['deadline_at'] === $deadline,
    'Friday at 17:50 resumes to Monday at 09:20 in the local calendar.');
$expired = $read->read($support, $c, 'tickets', ['id' => $data['tickets']['expired_id']]);
$verify($expired['state'] === 'review' && $expired['review_reason'] === 'expired' && $expired['clocks'] === [],
    'The expired-contract request is retained for review without an SLA clock.');
$report = ['id' => $data['report_id'], 'kind' => 'agreement'];
$summary = $read->read($manager, $data['group'], 'reports', $report);
$verify(array_values(array_unique(array_column($summary['items'], 'company_id'))) === [$a, $b, $c], 'Group report includes all three authorized branches.');
$own = iterator_to_array((new Reports($db))->export($support, $c, $data['report_id'], ['kind' => 'agreement']), false);
$verify(count($own) === 2 && array_unique(array_column($own, 'company_id')) === [$c], 'C CSV query contains only C contracts.');
$missing(fn () => $read->read($support, $c, 'reports', $report + ['scope_company' => $a]), 'C cannot drill down to A contracts.');
$billing = $demo->subscriptions($argv[1])['result'];
$verify($billing['original']['total_minor'] === 14000 && $billing['adjusted']['total_minor'] === 9000, 'Two locked demo bills reconcile to 140 and 90.');
$verify(Input::digest($billing) === Input::digest($demo->subscriptions($argv[1])['result']), 'Billing demo replay keeps the same trace.');
printf("Public demo: %d assertions passed.\n", $checks);
