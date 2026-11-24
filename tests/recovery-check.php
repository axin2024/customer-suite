<?php

declare(strict_types=1);

use CustomerSuite\Database;
use CustomerSuite\Input;
use CustomerSuite\Migrations;

require dirname(__DIR__) . '/src/bootstrap.php';

if (getenv('CRM_TEST_DATABASE') !== '1' || !isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Set CRM_TEST_DATABASE=1 and provide the group acceptance output file.');
}
$db = Database::connect();
$schema = $db->row('SELECT DATABASE() AS name')['name'];
if (!str_starts_with($schema, 'crm_test') || !(new Migrations($db, dirname(__DIR__) . '/database/migrations'))->ready()) {
    throw new RuntimeException('Recovery check requires an already migrated, disposable crm_test database.');
}
$file = fopen($argv[1], 'rb');
$evidence = json_decode(ltrim((string) fgets($file, 8193), "\xEF\xBB\xBF"), true, 24, JSON_THROW_ON_ERROR);
fclose($file);
if (!is_array($evidence) || count($evidence['contracts'] ?? []) !== 3) {
    throw new RuntimeException('Provide the JSON line emitted by management-reports.php --evidence.');
}
$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quoteId = Input::id($evidence['quote_id'] ?? null);
$contractIds = array_map(static fn (mixed $id): int => Input::id($id), $evidence['contracts']);
[$a, $b, $c] = array_map(static fn (mixed $company): int => Input::id($company), array_keys($contractIds));
$quotes = $db->rows(
    "SELECT a.company_id,a.id,a.quote_revision,v.value_minor,v.evidence_ref
     FROM agreements a JOIN agreement_versions v ON v.company_id = a.company_id AND v.agreement_id = a.id AND v.revision = 1
     WHERE a.quote_id = ? AND a.company_id IN (?,?,?) ORDER BY a.company_id",
    [$quoteId, $a, $b, $c],
);
$must(array_column($quotes, 'id', 'company_id') === $contractIds, 'The three stored agreements do not match the group signing receipt.');

$aFact = $evidence['a'];
$forecast = $db->row(
    'SELECT opportunity_id,opportunity_version,value_minor,weighted_minor,probability_bps
     FROM forecast_rows WHERE company_id = ? AND snapshot_id = ? AND opportunity_id = ?',
    [$a, Input::id($aFact['forecast_id']), Input::id($aFact['opportunity_id'])],
);
$must($forecast !== null && $forecast['opportunity_version'] === $aFact['opportunity_version']
    && $forecast['value_minor'] === 100000000 && $forecast['weighted_minor'] === 60000000
    && $forecast['probability_bps'] === 6000, 'Recovered forecast does not match source opportunity and saved weighted value.');

$bFact = $evidence['b'];
$renewals = $db->rows(
    'SELECT id,agreement_id,agreement_revision,opportunity_id,cycle_ends_on FROM renewals WHERE company_id = ? AND agreement_id = ?',
    [$b, $contractIds[$b]],
);
$must(count($renewals) === 1 && $renewals[0]['id'] === $bFact['renewal_id'] && $renewals[0]['opportunity_id'] === $bFact['opportunity_id']
    && $renewals[0]['cycle_ends_on'] === $bFact['cycle_ends_on'], 'Recovered renewal must be unique and attached to the original cycle.');

$cFact = $evidence['c'];
$service = $db->row(
    "SELECT t.id,t.agreement_id,t.agreement_revision,p.calendar_id,p.revision AS policy_revision,s.deadline_at,
        (SELECT COUNT(*) FROM service_events e WHERE e.company_id = t.company_id AND e.ticket_id = t.id) AS event_count
     FROM service_tickets t JOIN service_policies p ON p.company_id = t.company_id AND p.id = t.policy_id
     JOIN sla_clocks s ON s.company_id = t.company_id AND s.ticket_id = t.id AND s.kind = 'response'
     WHERE t.company_id = ? AND t.id = ?",
    [$c, Input::id($cFact['ticket_id'])],
);
$must($service !== null && $service['agreement_id'] === $contractIds[$c]
    && $service['calendar_id'] === $cFact['calendar_id'] && $service['policy_revision'] === $cFact['policy_revision']
    && $service['deadline_at'] === $cFact['response_deadline_utc'] && $service['event_count'] === 1,
    'Recovered C ticket must retain one event and the original calendar, policy and deadline.');

$rFact = $evidence['report'];
$report = $db->row(
    "SELECT s.id,s.as_of,s.fx_set_id,r.object_id,r.stage,r.stage_event_id,r.amount_version,r.first_touch_id,r.recent_touch_id
     FROM report_snapshots s JOIN report_rows r ON r.report_id = s.id
     WHERE s.id = ? AND r.company_id = ? AND r.kind = 'opportunity' AND r.object_id = ?",
    [Input::id($rFact['id']), $a, Input::id($aFact['opportunity_id'])],
);
$must($report !== null && $report['stage_event_id'] === $rFact['stage_event_id'] && $report['stage'] === 'negotiation'
    && $report['as_of'] === Input::instant($rFact['as_of']) && $report['fx_set_id'] === $rFact['fx_set_id'],
    'Recovered report must retain its time, stage event and rate version.');

$attachments = $db->rows(
    'SELECT company_id,handover_id,agreement_id,agreement_revision,byte_length,sha256,
        SHA2(body,256) AS recovered_sha256,OCTET_LENGTH(body) AS recovered_length
     FROM delivery_acceptances ORDER BY company_id,handover_id',
);
foreach ($attachments as $asset) {
    $must(hash_equals($asset['sha256'], $asset['recovered_sha256']) && $asset['byte_length'] === $asset['recovered_length'],
        'A delivery attachment no longer matches its documented length or digest.');
}
echo Input::json(['contracts' => $quotes, 'forecast' => $forecast, 'renewals' => $renewals,
    'service' => $service, 'report' => $report, 'attachments' => $attachments]), PHP_EOL;
