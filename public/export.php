<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Contacts;
use CustomerSuite\Database;
use CustomerSuite\Input;
use CustomerSuite\Reports;
use CustomerSuite\SpreadsheetCsv;
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
    if (isset($_GET['report'])) {
        $id = Input::id($_GET['report'], '报表');
        $rows = (new Reports($db))->export($actor, $company, $id, $_GET);
        SpreadsheetCsv::send('report-' . $id . '.csv', [
            '公司编号', '公司', '种类', '业务编号', '标题', '客户编号', '业务版本', '阶段', '阶段事件号', '最深阶段',
            '金额版本', '币种', '原币最小单位', '汇率', '人民币分', '首次触点号', '首次渠道', '最近触点号', '最近渠道',
        ], $rows);
        exit;
    }
    $account = Input::id($_GET['account'] ?? null, '客户');
    $channel = Input::choice($_GET['channel'] ?? null, '渠道', ['email', 'phone']);
    $rows = (new Contacts($db))->directory($actor, $company, $account, $channel);
    SpreadsheetCsv::send('contacts-' . $account . '-' . $channel . '.csv', ['联系人编号', '姓名', '职位', $channel], $rows);
} catch (BusinessError $error) {
    WebResponse::json(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Customer Suite export: ' . $error::class . ' ' . $error->getCode());
    WebResponse::json(['error' => '导出暂时不可用。'], 503);
}
