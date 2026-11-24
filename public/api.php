<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Database;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Queries;
use CustomerSuite\WebResponse;
use CustomerSuite\WebSession;

require dirname(__DIR__) . '/src/bootstrap.php';

WebResponse::headers();
try {
    $db = Database::connect();
    $session = new WebSession($db);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $resource = $_GET['resource'] ?? null;
    $actor = $session->actor();
    if ($resource === 'session' && $method === 'GET') {
        WebResponse::json(['csrf' => $session->csrf(), 'user' => $actor?->user(), 'companies' => $actor?->companies()]);
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new BusinessError('该请求方法不可用。', 405);
    }
    if ($method === 'POST') {
        $session->verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type !== 'application/json') {
            throw new BusinessError('请求正文须为 JSON。', 415);
        }
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if ($raw === false || strlen($raw) > 262144) {
            throw new BusinessError('请求正文不能超过 256 KiB。', 413);
        }
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new BusinessError('JSON 正文须为对象。');
        }
        if ($resource === 'session') {
            $session->login($payload['email'] ?? null, $payload['password'] ?? null, $_SERVER['REMOTE_ADDR'] ?? '');
            WebResponse::json(['csrf' => $session->csrf(), 'user' => $session->actor()->user()]);
        }
        if ($actor === null) {
            throw new BusinessError('请先登录。', 401);
        }
        $company = Input::id($_GET['company'] ?? null, '公司编号');
        $action = Input::key($payload['action'] ?? null, '操作', 64);
        $fields = $payload['data'] ?? null;
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw new BusinessError('data 须为对象。');
        }
        session_write_close();
        WebResponse::json((new Operations($db))->execute($actor, $company, $action,
            Input::key($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, '请求标识', 128), $fields));
    }
    if ($actor === null) {
        throw new BusinessError('请先登录。', 401);
    }
    session_write_close();
    $company = Input::id($_GET['company'] ?? null, '公司编号');
    WebResponse::json((new Queries($db))->read($actor, $company, Input::key($resource, '资源', 32), $_GET));
} catch (BusinessError $error) {
    WebResponse::json(['error' => $error->getMessage(), 'details' => $error->details], $error->status);
} catch (JsonException) {
    WebResponse::json(['error' => 'JSON 正文无效。'], 400);
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(8));
    error_log('Customer Suite [' . $reference . '] ' . $error::class . ' ' . $error->getCode());
    WebResponse::json(['error' => '请求暂时无法处理。', 'reference' => $reference], 500);
}
