<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Queries;
use CustomerSuite\Receipts;
use CustomerSuite\Reports;

require __DIR__ . '/bootstrap.php';

$tag = bin2hex(random_bytes(5));
$password = 'Test-only-' . bin2hex(random_bytes(16));
$identity = new Identity($db);
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
$admin = new Access($db, $root['id'] ?? $identity->bootstrap('系统管理员', $tag . '@example.test', $password));
$user = static fn (string $role): Access => new Access($db, $identity->addUser($admin, '合成用户 ' . $role, "$tag.$role@example.test", $password));
$manager = $user('manager');
$aSeller = $user('a');
$bSeller = $user('b');
$cSeller = $user('c');
$anotherSeller = $user('peer');
$hq = $identity->addCompany($admin, 'R-HQ-' . $tag, '报表总部');
$a = $identity->addCompany($admin, 'R-A-' . $tag, '甲数据公司', $hq);
$b = $identity->addCompany($admin, 'R-B-' . $tag, '乙独立数据公司', $hq);
$c = $identity->addCompany($admin, 'R-C-' . $tag, '丙独立数据公司', $hq);
foreach ([$hq, $a, $b] as $company) {
    $identity->membership($admin, $company, $manager->userId, 'manager', true);
}
foreach ([$a => $aSeller, $b => $bSeller, $c => $cSeller] as $company => $seller) {
    $identity->membership($admin, $company, $seller->userId, 'sales', true);
}
$identity->membership($admin, $a, $anotherSeller->userId, 'sales', true);
$ops = new Operations($db);
$queries = new Queries($db);
$write = static fn (Access $actor, int $company, string $action, array $input, ?string $key = null): array =>
    $ops->execute($actor, $company, $action, $key ?? bin2hex(random_bytes(12)), $input)['result'];
$start = '2026-11-10';
$setTime = static fn (string $value) => $db->run('SET timestamp = ' . (new DateTimeImmutable($value))->getTimestamp());
$setTime($start . 'T03:00:00Z');
$group = $write($manager, $hq, 'account.create', ['name' => '合成集团客户']);
$allocations = [];
foreach ([$a => $aSeller, $b => $bSeller, $c => $cSeller] as $company => $seller) {
    $account = $write($admin, $company, 'account.create', ['name' => '独立签约主体 ' . $company,
        'parent_company_id' => $hq, 'parent_id' => $group['id'], 'owner_id' => $seller->userId]);
    $allocations[$company] = ['company_id' => $company, 'account_id' => $account['id'], 'reference_no' => 'ALLOC-' . $company,
        'amount' => '1000', 'starts_on' => $start, 'ends_on' => $company === $b ? '2026-11-29' : '2026-12-31',
        'service_level' => 'standard', 'evidence_ref' => 'SYNTHETIC-SIGNED-' . $company];
}
$product = ['product_code' => 'PACKAGE', 'product_version' => '1', 'description' => '集团采购包', 'quantity' => 1];
$hqContact = $write($manager, $hq, 'contact.create', ['account_id' => $group['id'], 'name' => '采购代表', 'job_title' => '财务']);
$head = ['id' => $write($manager, $hq, 'opportunity.create', ['account_id' => $group['id'], 'title' => '集团统采',
    'currency' => 'CNY', 'amount' => '3000', 'expected_close' => '2026-11-30'])['id']];
$write($manager, $hq, 'opportunity.move', $head + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $hqContact['id']]);
$write($manager, $hq, 'opportunity.products', $head + ['version' => 2, 'products' => [$product + ['unit_price' => '3000']]]);
$write($manager, $hq, 'opportunity.move', $head + ['version' => 3, 'stage' => 'proposal']);
$quote = ['id' => $write($manager, $hq, 'quote.prepare', ['opportunity_id' => $head['id'], 'opportunity_version' => 4, 'discount_bps' => 0,
    'expires_on' => '2026-11-30', 'terms' => '各分公司独立签署'])['id']];
$write($manager, $hq, 'quote.submit', $quote + ['version' => 1]);
$write($admin, $hq, 'agreement.sign', $quote + ['version' => 2, 'scope' => 'group', 'allocations' => array_values($allocations)]);
$contracts = array_column($db->rows('SELECT id,company_id FROM agreements WHERE source_company_id = ? AND quote_id = ?', [$hq, $quote['id']]), 'id', 'company_id');
same(3, count($contracts), 'Three independent legal contracts from one headquarters quote.');

$first = $write($manager, $a, 'campaign.create', ['code' => 'PUBLIC', 'name' => '展会', 'channel' => 'event']);
$recent = $write($manager, $a, 'campaign.create', ['code' => 'SITE', 'name' => '官网', 'channel' => 'web']);
$leadSample = ['name' => '生意展会线索', 'email' => 'referral@example.test', 'source' => 'report-sample'];
$lead = $write($manager, $a, 'lead.receive', $leadSample + ['campaign_id' => $first['id'], 'external_id' => 'first', 'occurred_at' => $start . 'T03:00:00Z']);
$next = $write($manager, $a, 'lead.receive', $leadSample + ['campaign_id' => $recent['id'], 'external_id' => 'recent', 'occurred_at' => $start . 'T03:00:00Z']);
$claim = $write($aSeller, $a, 'lead.claim', ['id' => $lead['id'], 'version' => $next['version']]);
$aCustomer = $allocations[$a]['account_id'];
$qualified = $write($aSeller, $a, 'lead.qualify', ['id' => $lead['id'], 'version' => $claim['version'],
    'account_id' => $aCustomer, 'next_follow_up' => '2026-11-20']);
$aContact = $write($aSeller, $a, 'contact.create', ['account_id' => $aCustomer, 'name' => '跟进代表', 'job_title' => '采购']);
$aOpp = ['id' => $write($aSeller, $a, 'opportunity.create', ['account_id' => $aCustomer, 'lead_id' => $lead['id'], 'lead_version' => $qualified['version'],
    'title' => '=甲容量商机', 'currency' => 'CNY', 'amount' => '250', 'expected_close' => '2026-11-30'])['id']];
$write($aSeller, $a, 'opportunity.move', $aOpp + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $aContact['id']]);
$write($aSeller, $a, 'opportunity.products', $aOpp + ['version' => 2, 'products' => [$product + ['unit_price' => '1000000']]]);
$write($aSeller, $a, 'opportunity.move', $aOpp + ['version' => 3, 'stage' => 'proposal']);
$write($aSeller, $a, 'opportunity.move', $aOpp + ['version' => 4, 'stage' => 'negotiation']);
$forecast = $write($manager, $a, 'forecast.capture', ['label' => '甲商机预测', 'period_start' => $start, 'period_end' => '2026-12-01']);
$forecastRow = $queries->read($manager, $a, 'forecasts', ['id' => $forecast['id']])['items'][0];
same(60000000, $forecastRow['weighted_minor'], 'Integrated forecast produces 600,000 CNY.');
$target = $allocations[$b]['account_id'];
$bOpp = $write($bSeller, $b, 'opportunity.create', ['account_id' => $target, 'title' => '乙日元商机',
    'currency' => 'JPY', 'amount' => '10000', 'expected_close' => '2026-11-30'])['id'];
$historical = $start . 'T03:02:00Z';
$setTime($start . 'T03:05:00Z');
$write($aSeller, $a, 'opportunity.move', $aOpp + ['version' => 5, 'stage' => 'proposal', 'reason' => '重新核价']);
$write($aSeller, $a, 'opportunity.products', $aOpp + ['version' => 6, 'products' => [$product + ['unit_price' => '1250000']]]);
$privateAccount = $write($anotherSeller, $a, 'account.create', ['name' => '同分公司不同销售客户']);
$other = $write($anotherSeller, $a, 'opportunity.create', ['account_id' => $privateAccount['id'], 'title' => '同公司他人商机',
    'currency' => 'CNY', 'amount' => '12', 'expected_close' => '2026-11-30']);
$rateInfo = ['rate_date' => $start, 'source' => '合成样例汇率'];
$badRates = $write($manager, $hq, 'report.fx', $rateInfo + ['rates' => []]);
$rates = $write($manager, $hq, 'report.fx', $rateInfo + ['rates' => ['JPY' => '0.04750']]);
$payload = ['label' => '总部三家经营汇总', 'fx_set_id' => $rates['id'], 'as_of' => $historical, 'company_ids' => [$a, $b, $c]];
rejects(404, fn () => $write($manager, $hq, 'report.capture', $payload), 'Parent role alone provides no access to a third subsidiary.');
$identity->membership($admin, $c, $manager->userId, 'manager', true);
rejects(422, fn () => $write($manager, $hq, 'report.capture', array_replace($payload, ['fx_set_id' => $badRates['id']])), 'Missing rates refuse an incomplete snapshot.');
same([], $queries->read($manager, $hq, 'reports', [])['items'], 'Failed capture rolls back header and rows.');
$snapshot = $write($manager, $hq, 'report.capture', $payload, 'group-report');
$report = ['id' => $snapshot['id']];
$details = $queries->read($manager, $hq, 'reports', $report);
same([$a, $b, $c], array_column($details['companies'], 'id'), 'AC-15 HQ reads three independently authorized subsidiaries.');
same(5, $snapshot['row_count'], 'Historical reports exclude records created after the cutoff.');
$groupContracts = array_column($details['group_totals'], 'cny_minor', 'kind');
same('300000', (string) $groupContracts['agreement'], 'Three legal contracts roll up to 3,000 CNY.');
$aDrill = $queries->read($manager, $hq, 'reports', $report + ['scope_company' => $a, 'kind' => 'opportunity']);
$row = $aDrill['items'][0];
same($aOpp['id'], $row['object_id'], 'Drilldown lands on the original business record.');
same('negotiation', $row['stage'], 'Stage is computed as of historical time, despite rollback afterward.');
same(5, $row['source_version'], 'Snapshot pins version of the source stage.');
same(3, $row['amount_version'], 'Snapshot pins independent amount version.');
same(100000000, $row['value_minor'], 'Amount before historical cutoff remains 1,000,000 CNY.');
same('event', $row['first_channel'], 'First attribution is deterministic even at equal touch time.');
same('web', $row['recent_channel'], 'Recent attribution uses newest matching tie ID.');
same(1, (int) $aDrill['funnel'][0]['negotiation'], 'Ever-reached stage is counted in the funnel.');
$bDrill = $queries->read($manager, $hq, 'reports', $report + ['scope_company' => $b, 'kind' => 'opportunity'])['items'][0];
same($bOpp, $bDrill['object_id'], 'JPY record is attributed to the correct company.');
same('47500', (string) $bDrill['cny_minor'], 'Zero-decimal JPY converted to CNY cents with exact decimal arithmetic.');
same('0.04750000', $bDrill['fx_rate'], 'Applied rate kept at eight-digit scale.');

$setTime('2026-11-20T10:02:00Z');
$identity->membership($admin, $b, $bSeller->userId, 'success', true);
$write($manager, $b, 'success.assign', ['agreement_id' => $contracts[$b], 'agreement_version' => 1,
    'owner_id' => $bSeller->userId, 'reason' => '乙分公司续约负责人']);
$scanned = $write($manager, $b, 'renewal.scan', ['as_of' => '2026-11-20']);
same(1, $scanned['created_count'], 'Integrated B contract creates exactly one renewal.');
$bRenewal = $scanned['items'][0];
same($contracts[$b], $bRenewal['agreement_id'], 'Integrated renewal references B allocation.');
same(0, $write($manager, $b, 'renewal.scan', ['as_of' => '2026-11-20'])['created_count'], 'Rescanning B produces no second opportunity.');
$renewalDetails = $queries->read($bSeller, $b, 'renewals', ['id' => $bRenewal['id']]);
same('2026-11-29', $renewalDetails['cycle_ends_on'], 'B renews the end date of its independent agreement.');

$identity->membership($admin, $c, $cSeller->userId, 'service', true);
$calendar = $write($manager, $c, 'service.calendar', ['code' => 'C-LOCAL', 'name' => '丙分公司工作日',
    'timezone' => 'Asia/Shanghai', 'week' => array_fill_keys([1, 2, 3, 4, 5], [['09:00', '12:00'], ['13:00', '18:00']]), 'exceptions' => []]);
$limits = ['P1' => ['response' => 15, 'resolution' => 60], 'P2' => ['response' => 30, 'resolution' => 120],
    'P3' => ['response' => 90, 'resolution' => 360], 'P4' => ['response' => 120, 'resolution' => 480]];
$write($manager, $c, 'service.policy', ['service_level' => 'standard', 'effective_from' => '2026-11-10T00:00:00Z',
    'calendar_id' => $calendar['id'], 'limits' => $limits, 'pause_for_customer' => true]);
$queue = $write($manager, $c, 'service.queue', ['code' => 'C-HELP', 'name' => '丙分公司支持']);
$write($manager, $c, 'service.route', ['category' => 'incident', 'queue_id' => $queue['id']]);
$write($manager, $c, 'service.grant', ['agreement_id' => $contracts[$c], 'user_id' => $cSeller->userId, 'allowed' => true]);
$event = ['agreement_id' => $contracts[$c], 'category' => 'incident', 'priority' => 'P2', 'title' => '丙分公司接入支持请求',
    'description' => '合成支持事件', 'occurred_at' => '2026-11-20T17:50:00+08:00', 'source' => 'group-demo', 'external_id' => 'C-FIRST'];
$ticket = $write($cSeller, $c, 'ticket.receive', $event, 'group-ticket');
same($queue['id'], $ticket['queue_id'], 'Integrated C ticket is routed to the company queue.');
same('2026-11-23 01:20:00.000000', $ticket['clocks']['response']['deadline_at'], 'Friday 17:50 C ticket resumes on Monday under its own calendar.');
$ticketDetails = $queries->read($cSeller, $c, 'tickets', ['id' => $ticket['id']]);
same($calendar['id'], $ticketDetails['calendar']['id'], 'Ticket details trace its pinned calendar.');
same(Input::digest($ticket), Input::digest($write($cSeller, $c, 'ticket.receive', $event, 'group-ticket')), 'Same request key returns the original C ticket.');
same(Input::digest($ticket), Input::digest($write($cSeller, $c, 'ticket.receive', $event)), 'Replays with a fresh request key still preserve the source event identity.');
same(1, $db->row('SELECT COUNT(*) AS n FROM service_tickets WHERE company_id = ? AND agreement_id = ?', [$c, $contracts[$c]])['n'],
    'Replay leaves one persistent C ticket.');
foreach ([$a => $aSeller, $b => $bSeller, $c => $cSeller] as $company => $seller) {
    $otherCompanies = array_diff_key($contracts, [$company => true]);
    foreach ($otherCompanies as $sibling => $siblingContract) {
        rejects(404, fn () => $queries->read($seller, $company, 'agreements', ['id' => $siblingContract]), 'Legal ledger rejects sibling ID in local context.');
        rejects(404, fn () => $queries->read($seller, $sibling, 'agreements', ['id' => $siblingContract]), 'Legal ledger rejects sibling company context.');
    }
}

$export = new Reports($db);
$aRows = iterator_to_array($export->export($aSeller, $a, $snapshot['id'], []));
same([$a, $a], array_column($aRows, 'company_id'), 'AC-16 Sales export contains only records from its own company.');
same([$contracts[$a]], array_column(array_filter($aRows, static fn (array $item): bool => $item['kind'] === 'agreement'), 'object_id'),
    'AC-16 The sibling contract IDs do not appear.');
same($aRows, $queries->read($aSeller, $a, 'reports', $report)['items'], 'CSV row source and detail use exactly the same visibility and fields.');
same([], $queries->read($anotherSeller, $a, 'reports', $report)['items'], 'Peer cannot view report rows owned by another seller.');
rejects(404, fn () => $export->export($aSeller, $a, $snapshot['id'], ['scope_company' => $b]), 'Forged scope cannot export sibling records.');
rejects(404, fn () => $export->export($aSeller, $hq, $snapshot['id'], []), 'Forged company context fails before export.');
$identity->membership($admin, $c, $manager->userId, 'manager', false);
same([$a, $b], array_column($queries->read($manager, $hq, 'reports', $report)['companies'], 'id'), 'Current permissions remove old snapshot scope on read.');
rejects(404, fn () => $export->export($manager, $hq, $snapshot['id'], ['scope_company' => $c]), 'Revoked company is excluded from old CSVs.');
rejects(404, fn () => (new Receipts($db))->read($manager, $hq, 'group-report'), 'Capture receipt cannot expose original aggregate count after access changes.');
$write($manager, $a, 'account.revise', ['id' => $aCustomer, 'version' => 1, 'owner_id' => $anotherSeller->userId]);
same([], iterator_to_array($export->export($aSeller, $a, $snapshot['id'], [])), 'Account reassignment removes data from old sales exports.');
$db->run('SET timestamp = 0');
if (($argv[1] ?? null) === '--evidence') {
    echo Input::json([
        'fixture' => $tag,
        'quote_id' => $quote['id'],
        'contracts' => $contracts,
        'a' => ['forecast_id' => $forecast['id'], 'opportunity_id' => $forecastRow['opportunity_id'],
            'opportunity_version' => $forecastRow['opportunity_version'], 'value_minor' => $forecastRow['value_minor'],
            'weighted_minor' => $forecastRow['weighted_minor']],
        'b' => ['renewal_id' => $bRenewal['id'], 'opportunity_id' => $bRenewal['opportunity_id'], 'cycle_ends_on' => $renewalDetails['cycle_ends_on']],
        'c' => ['ticket_id' => $ticket['id'], 'calendar_id' => $calendar['id'], 'policy_revision' => $ticket['policy_revision'],
            'response_deadline_utc' => $ticket['clocks']['response']['deadline_at']],
        'report' => ['id' => $report['id'], 'stage_event_id' => $row['stage_event_id'], 'as_of' => $historical, 'fx_set_id' => $rates['id']],
    ]), PHP_EOL;
}
done('Management reports, historical funnel, rate conversion and company isolation');
