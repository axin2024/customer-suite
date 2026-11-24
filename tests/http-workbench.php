<?php

declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Accounts;
use CustomerSuite\Contacts;
use CustomerSuite\Identity;
use CustomerSuite\Input;

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
$sent = $client->call($path, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
same(303, $sent['code'], 'Normal workbench form commits and redirects.');
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
$identity->membership($admin, $a, $seller, 'sales', false);
same(401, $client->call('/api.php?resource=accounts&company=' . $a)['code'], 'Existing browser session expires after role revocation.');
done('HTTP workbench, cookies, request recovery and CSV');
