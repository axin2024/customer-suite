<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Contacts;
use CustomerSuite\Database;
use CustomerSuite\Input;
use CustomerSuite\WebResponse;
use CustomerSuite\WebSession;

require dirname(__DIR__) . '/src/bootstrap.php';

WebResponse::headers();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        throw new BusinessError('该请求方法不可用。', 405);
    }
    $db = Database::connect();
    $session = new WebSession($db);
    $actor = $session->actor() ?? throw new BusinessError('请先登录。', 401);
    session_write_close();
    $company = Input::id($_GET['company'] ?? null, '公司');
    $account = Input::id($_GET['account'] ?? null, '客户');
    $channel = Input::choice($_GET['channel'] ?? null, '渠道', ['email', 'phone']);
    $rows = (new Contacts($db))->directory($actor, $company, $account, $channel);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="contacts-' . $account . '-' . $channel . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['联系人编号', '姓名', '职位', $channel], ',', '"', '');
    foreach ($rows as $row) {
        $cells = array_map(static function (int|string $value): string {
            return preg_match('/^\s*[=+\-@]/u', (string) $value) ? "'" . $value : (string) $value;
        }, array_values($row));
        fputcsv($out, $cells, ',', '"', '');
    }
    fclose($out);
} catch (BusinessError $error) {
    WebResponse::json(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Customer Suite export: ' . $error::class . ' ' . $error->getCode());
    WebResponse::json(['error' => '导出暂时不可用。'], 503);
}
