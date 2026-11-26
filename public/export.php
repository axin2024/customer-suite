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
    if (($_GET['kind'] ?? '') === 'billing-open') {
        SpreadsheetCsv::send('billing-open-'.$company.'.csv',['类型','订阅号','处理编号','关联编号'],
            (new CustomerSuite\SubscriptionPilot($db))->outstanding($actor,$company));
        exit;
    }
    if (isset($_GET['statement'])) {
        $actor->allow($company,'finance','manager');
        $id = Input::id($_GET['statement'],'账单');
        $bill = (new CustomerSuite\SubscriptionBilling($db))->detail($actor,$company,$id);
        if ($bill['state'] !== 'locked') {
            throw new BusinessError('请先复核并锁定账单。',409);
        }
        $rows = [];
        foreach ($bill['lines'] as $line) {
            $rows[] = [$id,$bill['statement_rev'],$bill['subscription_id'],$bill['billing_period'],$line['id'],$line['origin_period'],
                $line['kind'],$line['usage_id'],$line['plan_id'],$line['rate_rev'],$bill['currency'],$line['quantity'],$line['amount_minor']];
        }
        SpreadsheetCsv::send('statement-'.$id.'.csv',['账单号','版本','订阅号','账期','行号','来源账期','费用项目','原用量号','套餐号','价格版本','币种','数量','最小货币单位'],$rows);
        exit;
    }
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
