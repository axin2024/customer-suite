<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Contacts;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Opportunities;
use CustomerSuite\Operations;

require __DIR__ . '/bootstrap.php';

final class WorkbenchClient
{
    private ?string $cookie = null;
    public string $csrf = '';

    public function __construct(private readonly string $base)
    {
    }

    public function call(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
    {
        if ($this->cookie !== null) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'content' => $body ?? '',
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]);
        $result = file_get_contents($this->base . $path, false, $context);
        if ($result === false || empty($http_response_header)) {
            throw new RuntimeException('Could not read HTTP response for ' . $path);
        }
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie:\s*(crm_session=[^;]+)/i', $header, $values)) {
                $this->cookie = $values[1];
            }
        }
        return ['code' => (int) explode(' ', $http_response_header[0])[1], 'body' => $result, 'headers' => $http_response_header];
    }

    public function login(string $email, string $password): void
    {
        $path = '/api.php?resource=session';
        $initial = json_decode($this->call($path)['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->csrf = $initial['csrf'];
        $result = $this->json($path, ['email' => $email, 'password' => $password]);
        same(200, $result['code'], 'Session login over HTTP.');
        $this->csrf = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR)['csrf'];
    }

    public function json(string $path, array $payload, ?string $key = null): array
    {
        $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        return $this->call($path, 'POST', Input::json($payload), $headers);
    }

    public function sendFormAndDisconnect(string $path, string $body): void
    {
        $host = substr($this->base, strlen('http://'));
        $wire = "POST $path HTTP/1.1\r\nHost: $host\r\nCookie: $this->cookie\r\nConnection: close\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        $socket = stream_socket_client('tcp://' . $host, $number, $error, 5);
        if ($socket === false) {
            throw new RuntimeException('Could not open loopback socket: ' . $error);
        }
        try {
            for ($sent = 0; $sent < strlen($wire); $sent += $size) {
                $size = fwrite($socket, substr($wire, $sent));
                if (!$size) {
                    throw new RuntimeException('Could not send complete workbench request.');
                }
            }
        } finally {
            fclose($socket);
        }
    }

    public function upload(string $path, array $fields, string $fileName, string $fileBytes): array
    {
        $boundary = 'CrmTest-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"evidence_upload\"; filename=\"$fileName\"\r\n"
            . "Content-Type: text/plain\r\n\r\n$fileBytes\r\n--$boundary--\r\n";
        return $this->call($path, 'POST', $body, ['Content-Type: multipart/form-data; boundary=' . $boundary]);
    }
}

$base = getenv('CRM_TEST_URL') ?: '';
if (!preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D', $base)) {
    throw new RuntimeException('Set CRM_TEST_URL to the local disposable web server.');
}
$tag = bin2hex(random_bytes(5));
$identity = new Identity($db);
$password = 'Test-only-' . bin2hex(random_bytes(16));
$adminRow = $db->row('SELECT id FROM users WHERE is_admin = 1 LIMIT 1');
$admin = new Access($db, $adminRow ? (int) $adminRow['id'] : $identity->bootstrap('管理员', $tag . '@example.test', $password));
$email = $tag . '.seller@example.test';
$seller = $identity->addUser($admin, '测试工作台销售', $email, $password);
$a = $identity->addCompany($admin, 'A-HTTP-' . $tag, '第一分公司工作台');
$b = $identity->addCompany($admin, 'B-HTTP-' . $tag, '第二分公司工作台');
$identity->membership($admin, $a, $seller, 'sales', true);
$actor = new Access($db, $seller);
$accounts = new Accounts($db);
$contactAccount = $accounts->create($actor, $a, ['name' => '合成联系人公司']);
$sisterAccount = $accounts->create($admin, $b, ['name' => '另一分公司私有合同客户']);
$contacts = new Contacts($db);
$contact = $contacts->save($actor, $a, $contactAccount['id'], ['name' => '=1+1', 'job_title' => '采购人员', 'email' => 'peer@example.test']);
$permission = ['purpose' => 'export', 'channel' => 'email', 'allowed' => true, 'evidence' => 'DOC-A1'];
$contacts->permission($actor, $a, $contactAccount['id'], $contact['id'], 1, $permission);

$client = new WorkbenchClient($base);
same('{"status":"ready"}', $client->call('/health.php')['body'], 'Web health verifies all migrations.');
same(401, $client->call('/api.php?resource=accounts&company=' . $a)['code'], 'Anonymous cannot view company data.');
$loginPage = $client->call('/');
same(true, str_contains($loginPage['body'], '登录客户运营工作台'), 'Logged-out workbench renders the login form.');
same(true, in_array('X-Frame-Options: DENY', $loginPage['headers'], true), 'Workbench includes framing protection.');
$client->login($email, $password);
$path = '/?company=' . $a . '&view=accounts';
$page = $client->call($path);
same(200, $page['code'], 'Customer workbench opens under assigned company.');
same(true, str_contains($page['body'], '第一分公司工作台'), 'Workbench displays current company.');
same(false, str_contains($page['body'], '第二分公司工作台'), 'Company selector excludes unrelated company.');
same(false, str_contains($page['body'], '组织与权限'), 'Seller does not see admin area.');
same(true, str_contains($page['body'], '合成联系人公司'), 'List shows durable assigned records.');
same(404, $client->call('/?company=' . $b . '&view=accounts')['code'], 'Sibling company workbench is denied.');
same(404, $client->call('/api.php?resource=accounts&company=' . $a . '&id=' . $sisterAccount['id'])['code'], 'Sibling account ID is denied in current scope.');

$requestKey = 'web-lost-reply-' . $tag;
$body = http_build_query([
    'action' => 'account.create', 'csrf' => $client->csrf, 'request_key' => $requestKey,
    'data' => ['name' => '回执复查客户'],
]);
$client->sendFormAndDisconnect($path, $body);
for ($attempt = 0; $attempt < 60; $attempt++) {
    if ($db->row('SELECT 1 FROM operation_receipts WHERE company_id = ? AND actor_id = ? AND request_key = ? AND response IS NOT NULL',
        [$a, $seller, $requestKey])) {
        break;
    }
    usleep(50000);
}
$receipt = $client->call('/api.php?resource=receipts&company=' . $a . '&key=' . $requestKey);
same(200, $receipt['code'], 'Query by request ID recovers committed result.');
$id = json_decode($receipt['body'], true, 512, JSON_THROW_ON_ERROR)['result']['id'];
$repeat = $client->json('/api.php?company=' . $a, ['action' => 'account.create', 'data' => ['name' => '回执复查客户']], $requestKey);
same(200, $repeat['code'], 'ERP-style retry works through the CRM JSON endpoint.');
$replay = json_decode($repeat['body'], true, 512, JSON_THROW_ON_ERROR);
same(true, $replay['replayed'], 'The JSON retry reuses a form operation receipt.');
same($id, $replay['result']['id'], 'Form and JSON endpoints return the same CRM object ID.');
same(1, (int) $db->row('SELECT COUNT(*) AS n FROM accounts WHERE company_id = ? AND name = ?', [$a, '回执复查客户'])['n'],
    'Both HTTP requests create exactly one account.');
same(409, $client->json('/api.php?company=' . $a, ['action' => 'account.create', 'data' => ['name' => '请求内容变化']], $requestKey)['code'],
    'Reusing the same request ID with changed data is rejected.');
same(403, $client->call('/api.php?company=' . $a, 'POST', '{}', ['Content-Type: application/json'])['code'], 'Missing CSRF is denied.');
same(405, $client->call('/api.php?company=' . $a, 'PUT')['code'], 'Unsupported mutation method denied.');

$export = '/export.php?company=' . $a . '&account=' . $contactAccount['id'] . '&channel=email';
$before = $client->call($export);
same(200, $before['code'], 'Authorized contacts CSV downloads.');
same(true, str_contains($before['body'], 'peer@example.test'), 'Consented address is present.');
same(true, str_contains($before['body'], "'=1+1"), 'CSV neutralizes formula interpretation.');
$contacts->permission($actor, $a, $contactAccount['id'], $contact['id'], 2, array_replace($permission, ['allowed' => false]));
same(false, str_contains($client->call($export)['body'], 'peer@example.test'), 'CSV rechecks consent when generated.');
same(404, $client->call('/export.php?company=' . $b . '&account=' . $sisterAccount['id'] . '&channel=email')['code'],
    'Contact CSV cannot cross company boundary.');

$opportunities = new Opportunities($db);
$tomorrow = gmdate('Y-m-d', time() + 86400);
$nextMonth = gmdate('Y-m-d', time() + 30 * 86400);
$opp = $opportunities->create($actor, $a, ['account_id' => $contactAccount['id'], 'title' => '设备维护报价',
    'currency' => 'CNY', 'amount' => '2400.00', 'expected_close' => $nextMonth]);
$opportunities->move($actor, $a, $opp['id'], 1, ['stage' => 'qualified', 'decision_contact_id' => $contact['id']]);
$opportunities->updateProducts($actor, $a, $opp['id'], 2, [[
    'product_code' => 'MAINT', 'product_version' => '1.0', 'description' => '维护服务', 'quantity' => 2, 'unit_price' => '1200.00',
]]);
$opportunities->move($actor, $a, $opp['id'], 3, ['stage' => 'proposal']);
$opportunityPath = '/?company=' . $a . '&view=opportunities&id=' . $opp['id'];
same(true, str_contains($client->call($opportunityPath)['body'], '草拟报价'), 'Opportunity screen provides quote form.');
$newQuote = $client->call($opportunityPath, 'POST', http_build_query([
    'action' => 'quote.prepare', 'csrf' => $client->csrf, 'request_key' => 'quote-' . $tag,
    'data' => ['opportunity_id' => $opp['id'], 'opportunity_version' => 4, 'discount_bps' => 0, 'expires_on' => $tomorrow, 'terms' => '维护服务条款'],
]), ['Content-Type: application/x-www-form-urlencoded']);
same(303, $newQuote['code'], 'Workbench form saves quote through shared operations.');
$quote = $db->row('SELECT id FROM quotes WHERE company_id = ? AND opportunity_id = ?', [$a, $opp['id']]);
$quotePath = '/?company=' . $a . '&view=quotes&id=' . $quote['id'];
same(true, str_contains($client->call($quotePath)['body'], '提交折扣审批'), 'Quote view renders product snapshot and approval form.');
$api = '/api.php?company=' . $a;
same(200, $client->json($api, ['action' => 'quote.submit', 'data' => $quote + ['version' => 1]], 'submit-' . $tag)['code'], 'Quote can pass normal authorization over the JSON API.');
same(true, str_contains($client->call($quotePath)['body'], 'data[allocations][0][reference_no]'), 'Approved quote shows nested HTML signing fields.');
$signed = $client->call($quotePath, 'POST', http_build_query([
    'action' => 'agreement.sign', 'csrf' => $client->csrf, 'request_key' => 'sign-' . $tag,
    'data' => $quote + ['version' => 2, 'scope' => 'single', 'allocations' => [[
        'company_id' => $a, 'account_id' => $contactAccount['id'], 'reference_no' => 'SIGNED-' . $tag, 'amount' => '2400.00',
        'starts_on' => $tomorrow, 'ends_on' => $nextMonth, 'service_level' => 'standard', 'evidence_ref' => 'EVIDENCE-' . $tag,
    ]]],
]), ['Content-Type: application/x-www-form-urlencoded']);
same(303, $signed['code'], 'Nested HTML form issues a single-company agreement.');
$agreement = $db->row('SELECT id FROM agreements WHERE company_id = ? AND quote_id = ?', [$a, $quote['id']]);
same(true, str_contains($client->call($quotePath)['body'], '签约合同'), 'Signed quote links to the authorized legal contract.');
$contractPage = $client->call('/?company=' . $a . '&view=agreements&id=' . $agreement['id']);
same(200, $contractPage['code'], 'Contract detail responds successfully.');
same(true, str_contains($contractPage['body'], 'EVIDENCE-' . $tag), 'Contract detail renders archived signing reference.');
$deliveryEmail = $tag . '.delivery@example.test';
$deliveryId = $identity->addUser($admin, '测试工作台实施', $deliveryEmail, $password);
$identity->membership($admin, $a, $deliveryId, 'delivery', true);
$delivery = new Access($db, $deliveryId);
$ops = new Operations($db);
$opened = $ops->execute($actor, $a, 'handover.open', 'handover-' . $tag, [
    'agreement_id' => $agreement['id'], 'agreement_version' => 1, 'agreement_revision' => 1, 'owner_id' => $deliveryId,
    'contact_id' => $contact['id'], 'scope' => '维护服务培训与文档交付',
])['result'];
$work = ['id' => $opened['id']];
$ops->execute($actor, $a, 'handover.confirm', 'sales-scope-' . $tag, $work + ['version' => 1, 'side' => 'sales']);
$ops->execute($delivery, $a, 'handover.confirm', 'delivery-scope-' . $tag, $work + ['version' => 2, 'side' => 'delivery']);
$milestone = $ops->execute($delivery, $a, 'handover.milestone.add', 'add-milestone-' . $tag,
    $work + ['version' => 3, 'title' => '客户资料交接', 'due_on' => $tomorrow])['result'];
$ops->execute($delivery, $a, 'handover.milestone.complete', 'complete-milestone-' . $tag,
    $work + ['version' => 4, 'milestone_id' => $milestone['milestone_id']]);
$deliveryClient = new WorkbenchClient($base);
$deliveryClient->login($deliveryEmail, $password);
$handoverPath = '/?company=' . $a . '&view=handovers&id=' . $work['id'];
same(true, str_contains($deliveryClient->call($handoverPath)['body'], 'enctype="multipart/form-data"'), 'Assigned implementer sees real attachment form.');
$confirmationAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
$fileBytes = "Sample signed handover receipt.\r\n";
$uploaded = $deliveryClient->upload($handoverPath, [
    'action' => 'handover.accept', 'csrf' => $deliveryClient->csrf, 'request_key' => 'accept-upload-' . $tag,
    'data[id]' => $work['id'], 'data[version]' => 5, 'data[agreement_revision]' => 1, 'data[contact_id]' => $contact['id'],
    'data[confirmed_at]' => $confirmationAt, 'data[evidence_reference]' => 'CUSTOMER-HTTP-ACCEPT',
], 'signed-handover.txt', $fileBytes);
same(303, $uploaded['code'], 'Multipart browser upload preserves the signed handover evidence.');
same(true, str_contains($deliveryClient->call($handoverPath)['body'], '下载 signed-handover.txt'), 'Accepted workbench record exposes the attachment link.');
$download = $deliveryClient->call('/download.php?company=' . $a . '&handover=' . $work['id']);
same(200, $download['code'], 'Assigned implementer can download their evidence.');
same($fileBytes, $download['body'], 'Download returns exactly the uploaded bytes.');
same(true, in_array('Content-Digest: sha-256=:' . base64_encode(hash('sha256', $fileBytes, true)) . ':', $download['headers'], true),
    'Response carries digest of archived evidence.');
same(404, $client->call('/download.php?company=' . $b . '&handover=' . $work['id'])['code'], 'Evidence HTTP route denies cross-company download.');
$serviceEmail = $tag . '.service@example.test';
$serviceId = $identity->addUser($admin, '测试工作台服务人员', $serviceEmail, $password);
$identity->membership($admin, $a, $serviceId, 'service', true);
$ops->execute($admin, $a, 'service.grant', 'grant-ticket-' . $tag,
    ['agreement_id' => $agreement['id'], 'user_id' => $serviceId, 'allowed' => true]);
$serviceClient = new WorkbenchClient($base);
$serviceClient->login($serviceEmail, $password);
$ticketPath = '/?company=' . $a . '&view=tickets&state=review';
$servicePage = $serviceClient->call($ticketPath);
same(200, $servicePage['code'], 'Ticket workbench and review filter render for service user.');
same(false, str_contains($servicePage['body'], '服务设置'), 'Service user cannot access manager setting link.');
same(404, $serviceClient->call('/?company=' . $a . '&view=service-settings')['code'], 'Settings URL also checks manager permission.');
$receive = $serviceClient->call($ticketPath, 'POST', http_build_query([
    'action' => 'ticket.receive', 'csrf' => $serviceClient->csrf, 'request_key' => 'ticket-' . $tag,
    'data' => ['agreement_id' => $agreement['id'], 'category' => 'incident', 'priority' => 'P2', 'title' => '生效前的支持请求',
        'description' => '预先确认服务账号和工单入口', 'source' => 'test-http', 'external_id' => 'EVENT-' . $tag,
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1)],
]), ['Content-Type: application/x-www-form-urlencoded']);
same(303, $receive['code'], 'Service staff can record an out-of-coverage ticket through HTML.');
$reviewPage = $serviceClient->call($ticketPath);
same(true, str_contains($reviewPage['body'], '生效前的支持请求'), 'Preserved ticket appears in qualification review worklist.');
same(true, str_contains($reviewPage['body'], '合同尚未生效'), 'Worklist shows ineligibility reason in Chinese.');
$ticket = $db->row('SELECT id FROM service_tickets WHERE company_id = ? AND agreement_id = ?', [$a, $agreement['id']]);
$ticketDetailPath = '/?company=' . $a . '&view=tickets&id=' . $ticket['id'];
same(200, $serviceClient->call($ticketDetailPath)['code'], 'Scoped service ticket detail renders source and contract.');
same(404, $serviceClient->call('/?company=' . $b . '&view=tickets&id=' . $ticket['id'])['code'], 'Ticket page denies sibling company access.');
$identity->membership($admin, $a, $seller, 'manager', true);
$client->login($email, $password);
same(true, str_contains($client->call('/?company=' . $a . '&view=service-settings')['body'], '登记工作日历版本'), 'Manager can open versioned service configuration.');
same(true, str_contains($client->call($ticketDetailPath)['body'], '复核服务资格'), 'Manager sees eligibility decision form.');
$identity->membership($admin, $a, $seller, 'manager', false);
$identity->membership($admin, $a, $seller, 'sales', false);
same(401, $client->call('/api.php?resource=accounts&company=' . $a)['code'], 'Existing browser session expires after role revocation.');
done('HTTP workbench, cookies, request recovery and CSV');
