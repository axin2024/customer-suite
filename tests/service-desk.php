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
$identity = new Identity($db);
$password = 'Test-only-' . bin2hex(random_bytes(16));
$root = $db->row('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
$admin = new Access($db, $root['id'] ?? $identity->bootstrap('系统管理员', $tag . '@example.test', $password));
$a = $identity->addCompany($admin, 'A-T-' . $tag, '常规服务分公司');
$b = $identity->addCompany($admin, 'B-T-' . $tag, '合同过期分公司');
$createService = static function (int $company, string $name, string $local) use ($identity, $admin, $db, $tag, $password): Access {
    $user = $identity->addUser($admin, $name, "$tag.$local@example.test", $password);
    $identity->membership($admin, $company, $user, 'service', true);
    return new Access($db, $user);
};
$service = $createService($a, '常规服务人员', 'service');
$peer = $createService($a, '同公司其他服务人员', 'peer');
$sibling = $createService($b, '其他公司服务人员', 'sibling');
$ops = new Operations($db);
$query = new Queries($db);
$write = static fn (Access $actor, int $company, string $action, array $data = []): array =>
    $ops->execute($actor, $company, $action, bin2hex(random_bytes(12)), $data)['result'];
$office = [['09:00', '12:00'], ['13:00', '18:00']];
$week = array_fill_keys([1, 2, 3, 4, 5], $office);
$calendarInput = ['code' => 'CN-OFFICE', 'name' => '中国标准工作时段', 'timezone' => 'Asia/Shanghai', 'week' => $week, 'exceptions' => []];
$aCalendar = $write($admin, $a, 'service.calendar', $calendarInput);
$limits = ['P1' => ['response' => 15, 'resolution' => 60], 'P2' => ['response' => 30, 'resolution' => 120],
    'P3' => ['response' => 90, 'resolution' => 360], 'P4' => ['response' => 120, 'resolution' => 480]];
$policyInput = ['service_level' => 'standard', 'effective_from' => '2026-11-01T00:00:00Z', 'calendar_id' => $aCalendar['id'],
    'limits' => $limits, 'pause_for_customer' => true];
$write($admin, $a, 'service.policy', $policyInput);
rejects(404, fn () => $write($admin, $b, 'service.policy', $policyInput), 'SLA cannot attach a calendar from another company.');
$aQueue = $write($admin, $a, 'service.queue', ['code' => 'OPS', 'name' => '甲实施支持']);
$bQueue = $write($admin, $b, 'service.queue', ['code' => 'OPS', 'name' => '乙工单受理']);
$write($admin, $a, 'service.route', ['category' => 'incident', 'queue_id' => $aQueue['id']]);
$write($admin, $b, 'service.route', ['category' => 'incident', 'queue_id' => $bQueue['id']]);

$signed = static function (int $company, string $end) use ($db, $admin, $write): int {
    $account = (new Accounts($db))->create($admin, $company, ['name' => '合成服务台客户']);
    $contact = (new Contacts($db))->save($admin, $company, $account['id'], ['name' => '虚构管理员', 'job_title' => '信息化负责人']);
    $opp = ['id' => $write($admin, $company, 'opportunity.create', ['account_id' => $account['id'], 'title' => '服务签约项目',
        'currency' => 'CNY', 'amount' => '1000', 'expected_close' => '2026-12-01'])['id']];
    $write($admin, $company, 'opportunity.move', $opp + ['version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $contact['id']]);
    $write($admin, $company, 'opportunity.products', $opp + ['version' => 2, 'products' => [[
        'product_code' => 'SERVICE', 'product_version' => '1', 'description' => '支持服务', 'quantity' => 1, 'unit_price' => '1000',
    ]]]);
    $write($admin, $company, 'opportunity.move', $opp + ['version' => 3, 'stage' => 'proposal']);
    $quote = ['id' => $write($admin, $company, 'quote.prepare', ['opportunity_id' => $opp['id'], 'opportunity_version' => 4,
        'discount_bps' => 0, 'expires_on' => '2026-12-01', 'terms' => '合同工作日服务'])['id']];
    $write($admin, $company, 'quote.submit', $quote + ['version' => 1]);
    $write($admin, $company, 'agreement.sign', $quote + ['version' => 2, 'scope' => 'single', 'allocations' => [[
        'company_id' => $company, 'account_id' => $account['id'], 'amount' => '1000', 'reference_no' => 'SUPPORT-1',
        'starts_on' => '2026-11-10', 'ends_on' => $end, 'service_level' => 'standard', 'evidence_ref' => 'SIGNED-1',
    ]]]);
    return $db->row('SELECT id FROM agreements WHERE company_id = ? AND quote_id = ?', [$company, $quote['id']])['id'];
};
$setTime = static fn (string $time) => $db->run('SET timestamp = ' . (new DateTimeImmutable($time))->getTimestamp());
try {
    $setTime('2026-11-10T00:00:00Z');
    $contractA = $signed($a, '2026-12-31');
    $contractB = $signed($b, '2026-11-19');
    $setTime('2026-11-20T10:02:00Z');
    $write($admin, $a, 'service.grant', ['agreement_id' => $contractA, 'user_id' => $service->userId, 'allowed' => true]);
    $write($admin, $b, 'service.grant', ['agreement_id' => $contractB, 'user_id' => $sibling->userId, 'allowed' => true]);

    $received = ['agreement_id' => $contractA, 'category' => 'incident', 'priority' => 'P2', 'title' => '同步接口故障',
        'description' => '请求同步服务故障排查', 'occurred_at' => '2026-11-20T17:50:00+08:00', 'source' => 'support-email', 'external_id' => 'REQ-001'];
    rejects(404, fn () => $write($peer, $a, 'ticket.receive', $received), 'A service role alone cannot receive tickets for an ungranted contract.');
    $new = $write($service, $a, 'ticket.receive', $received);
    $protectedKey = 'service-revocation-' . $tag;
    $ops->execute($service, $a, 'ticket.receive', $protectedKey, $received);
    $id = ['id' => $new['id']];
    same('open', $new['state'], 'Valid contract is accepted and routed.');
    same($aQueue['id'], $new['queue_id'], 'Intake uses the event category queue of the contract company.');
    same('2026-11-23 01:20:00.000000', $new['clocks']['response']['deadline_at'], 'AC-11 Friday 17:50 response is due Monday at 09:20 local.');
    same('2026-11-23 02:50:00.000000', $new['clocks']['resolution']['deadline_at'], 'Resolution deadline runs independently.');
    same(Input::digest($new), Input::digest($write($service, $a, 'ticket.receive', array_replace($received, ['occurred_at' => '2026-11-20T09:50:00Z']))),
        'Same external event with equivalent time returns original receipt.');
    rejects(409, fn () => $write($service, $a, 'ticket.receive', array_replace($received, ['title' => '内容发生变化'])), 'Changes at an existing source key conflict explicitly.');
    same([], $query->read($peer, $a, 'tickets', [])['items'], 'Same-company peer without a grant cannot see worklist.');
    rejects(404, fn () => $query->read($sibling, $b, 'tickets', $id), 'Other company cannot probe valid ID.');
    rejects(404, fn () => $write($admin, $a, 'ticket.assign', $id + ['version' => 1, 'queue_id' => $bQueue['id'], 'reason' => '不合法的跨公司派发']), 'Queue must belong to same company.');
    rejects(422, fn () => $write($admin, $a, 'ticket.assign', $id + ['version' => 1, 'owner_id' => $peer->userId, 'reason' => '未授权人员']), 'Assignable service personnel must also have a grant.');
    $assigned = $write($service, $a, 'ticket.assign', $id + ['version' => 1, 'owner_id' => $service->userId, 'reason' => '认领入口告警']);
    same(2, $assigned['version'], 'Authorized service personnel can claim.');
    $weekend = $query->read($service, $a, 'tickets', $id + ['at' => '2026-11-20T10:01:00Z']);
    same(20.0, $weekend['clocks']['response']['remaining_minutes'], 'Only ten of thirty minutes elapsed on Friday.');

    $setTime('2026-11-23T01:32:00Z');
    $wait = $write($service, $a, 'ticket.advance', $id + ['version' => 2, 'action' => 'wait', 'reason' => '等客户确认连接日志', 'occurred_at' => '2026-11-23T01:00:00Z']);
    same('paused', $wait['clocks']['response']['state'], 'Waiting policy pauses unfinished response timer.');
    same('等客户确认连接日志', $wait['pause_reason'], 'Pause reason is available in receipt.');
    same(20.0, $query->read($service, $a, 'tickets', $id)['clocks']['response']['remaining_minutes'], 'Waiting time does not consume target.');
    $resume = $write($service, $a, 'ticket.advance', $id + ['version' => 3, 'action' => 'resume', 'reason' => '客户提供日志', 'occurred_at' => '2026-11-23T01:30:00Z']);
    same('2026-11-23 01:50:00.000000', $resume['clocks']['response']['deadline_at'], 'AC-11 Resume restores the remaining work time.');
    same('2026-11-23 03:20:00.000000', $resume['clocks']['resolution']['deadline_at'], 'Resolution follows the pause independently.');
    rejects(409, fn () => $write($service, $a, 'ticket.advance', $id + ['version' => 4, 'action' => 'respond', 'reason' => '过去事件',
        'occurred_at' => '2026-11-23T01:10:00Z']), 'Older events cannot overwrite current SLA state.');

    $holiday = $write($admin, $a, 'service.calendar', array_replace($calendarInput, ['exceptions' => ['2026-11-23' => []]]));
    $policyV2 = array_replace($policyInput, ['calendar_id' => $holiday['id'], 'effective_from' => '2026-11-23T01:30:00Z', 'pause_for_customer' => false]);
    same(2, $write($admin, $a, 'service.policy', $policyV2)['version'], 'New config appends a policy version.');
    same(1, $query->read($service, $a, 'tickets', $id)['calendar']['revision'], 'Existing ticket remains pinned to its calendar version.');
    $underNewPolicy = $write($service, $a, 'ticket.receive', array_replace($received, ['external_id' => 'REQ-002', 'occurred_at' => '2026-11-23T01:31:00Z']));
    same(2, $underNewPolicy['policy_revision'], 'New event selects newly effective policy.');
    same('2026-11-24 01:30:00.000000', $underNewPolicy['clocks']['response']['deadline_at'], 'New holiday calendar shifts deadline without mutating history.');
    $withoutPause = $write($service, $a, 'ticket.advance', ['id' => $underNewPolicy['id'], 'version' => 1, 'action' => 'wait', 'reason' => '等确认']);
    same('running', $withoutPause['clocks']['response']['state'], 'Policy can continue timing while waiting for a customer.');
    same(null, $withoutPause['pause_reason'], 'Waiting state only reports a clock pause when configured.');

    $setTime('2026-11-23T03:32:00Z');
    $respond = $write($service, $a, 'ticket.advance', $id + ['version' => 4, 'action' => 'respond', 'reason' => '回传临时方案', 'occurred_at' => '2026-11-23T01:40:00Z']);
    same('complete', $respond['clocks']['response']['state'], 'First response stops its timer.');
    same(20.0, $respond['clocks']['response']['elapsed_minutes'], 'Only eligible time through response is stored.');
    same('running', $respond['clocks']['resolution']['state'], 'Resolution timer continues after first response.');
    $sweep = $write($admin, $a, 'ticket.sweep', ['occurred_at' => '2026-11-23T03:21:00Z']);
    same(1, $sweep['count'], 'Overdue resolution escalates one ticket.');
    same(0, $write($admin, $a, 'ticket.sweep')['count'], 'Repeated sweep cannot duplicate an escalation.');
    $snapshot = $query->read($service, $a, 'tickets', $id);
    same('resolution', $snapshot['escalation']['clock_kind'], 'Escalation attaches the breached clock.');
    same($admin->userId, $snapshot['escalation']['detected_by'], 'Escalation records the true scheduling actor.');
    $done = $write($service, $a, 'ticket.advance', $id + ['version' => $snapshot['version'], 'action' => 'resolve', 'reason' => '恢复同步', 'occurred_at' => '2026-11-23T03:22:00Z']);
    $closed = $write($service, $a, 'ticket.advance', $id + ['version' => $done['version'], 'action' => 'close', 'reason' => '确认故障解除']);
    same('closed', $closed['state'], 'Resolved ticket can be closed.');
    same(1, (int) $db->row('SELECT COUNT(*) AS n FROM service_escalations WHERE company_id = ? AND ticket_id = ?', [$a, $id['id']])['n'], 'Only one durable escalation exists after later transitions.');
    same(Input::digest($new), Input::digest($write($service, $a, 'ticket.receive', $received)), 'Late retry returns original immutable external receipt.');

    $expired = $write($sibling, $b, 'ticket.receive', array_replace($received, ['agreement_id' => $contractB]));
    same('review', $expired['state'], 'AC-12 Expired contract does not drop incoming event.');
    same('expired', $expired['review_reason'], 'AC-12 Intake explicitly marks expired contract eligibility.');
    same([], $expired['clocks'], 'Expired agreement does not start SLA clocks.');
    same([$expired['id']], array_column($query->read($sibling, $b, 'tickets', ['state' => 'review'])['items'], 'id'), 'Retained request appears in scoped review worklist.');
    $recheck = ['id' => $expired['id'], 'version' => 1, 'decision' => 'recheck', 'reason' => '核对续签资料'];
    rejects(409, fn () => $write($admin, $b, 'ticket.review', $recheck), 'Manual recheck preserves ineligible request for later follow-up.');
    $closedReview = $write($admin, $b, 'ticket.review', array_replace($recheck, ['decision' => 'close']));
    same('closed', $closedReview['state'], 'Manager can explicitly close archived review request.');
    same('REQ-001', $query->read($sibling, $b, 'tickets', ['id' => $expired['id']])['source']['external_id'], 'Closing review retains source linkage.');
    $write($admin, $a, 'service.grant', ['agreement_id' => $contractA, 'user_id' => $service->userId, 'allowed' => false]);
    rejects(404, fn () => $query->read($service, $a, 'tickets', $id), 'Contract grant revocation removes read immediately.');
    rejects(404, fn () => $query->read($service, $a, 'receipts', ['key' => $protectedKey]), 'A prior receipt cannot bypass revoked contract access.');
    rejects(404, fn () => $ops->execute($service, $a, 'ticket.receive', $protectedKey, $received), 'Idempotent replay cannot reveal a now-inaccessible receipt.');
    same([], $query->read($service, $a, 'tickets', ['state' => 'closed'])['items'], 'Revoked service grant removes records from worklist.');
} finally {
    $db->run('SET timestamp = 0');
}
done('Service desk event retention, routing, SLA pause and one-time escalation');
