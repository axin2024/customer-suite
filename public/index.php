<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\BatchJobs;
use CustomerSuite\Database;
use CustomerSuite\Evidence;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\Outreach;
use CustomerSuite\Queries;
use CustomerSuite\WebResponse;
use CustomerSuite\WebSession;
use CustomerSuite\Workbench;

require dirname(__DIR__) . '/src/bootstrap.php';

WebResponse::headers();
header('Content-Type: text/html; charset=UTF-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$actor = null;
$session = null;
$error = null;
$receipt = null;
$chosen = null;
$company = null;
$section = null;
$result = null;
$companies = [];
$links = [];
$viewer = null;
$view = 'home';
$query = $_GET;
$workbench = null;
try {
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new BusinessError('该请求方法不可用。', 405);
    }
    $db = Database::connect();
    $session = new WebSession($db);
    $actor = $session->actor();
    $workbench = new Workbench($db);
    if ($actor !== null) {
        $viewer = $actor->user();
        $companies = $actor->companies();
        $scope = $query['company'] ?? null;
        $chosen = $scope === null ? ($companies[0]['id'] ?? null) : Input::id($scope, '公司编号');
        $company = $chosen === null ? null : $actor->company($chosen);
        $links = $workbench->navigation($actor, $chosen, (bool) $viewer['is_admin']);
        $view = Input::key($query['view'] ?? 'home', '页面', 32);
        if ($view !== 'home' && !isset($links[$view])) {
            throw new BusinessError('此页面不在当前公司授权范围内。', 404);
        }
    }
    if ($method === 'POST') {
        $session->verify($_POST['csrf'] ?? null);
        $action = Input::key($_POST['action'] ?? null, '操作', 64);
        if ($action === 'login') {
            $session->login($_POST['email'] ?? null, $_POST['password'] ?? null, $_SERVER['REMOTE_ADDR'] ?? '');
            WebResponse::redirect();
        }
        if ($action === 'logout') {
            $session->logout();
            WebResponse::redirect();
        }
        if ($actor === null) {
            throw new BusinessError('请先登录。', 401);
        }
        $fields = $_POST['data'] ?? [];
        if (!is_array($fields)) {
            throw new BusinessError('表单格式无效。');
        }
        if ($action === 'handover.accept' && isset($_FILES['evidence_upload'])) {
            $fields = array_replace($fields, Evidence::uploaded($_FILES['evidence_upload']));
        }
        $back = $chosen === null ? [] : ['company' => $chosen];
        if (str_starts_with($action, 'admin.')) {
            $result = $workbench->administrative($actor, $action, $fields);
            $_SESSION['flash'] = ['result' => $result, 'replayed' => false];
        } else {
            if ($chosen === null) {
                throw new BusinessError('请先选择公司。');
            }
            $_SESSION['flash'] = (new Operations($db))->execute($actor, $chosen, $action,
                Input::key($_POST['request_key'] ?? null, '请求标识', 128), $fields);
        }
        $back['view'] = $view;
        foreach (['id', 'state', 'queue', 'revision'] as $parameter) {
            if (isset($query[$parameter]) && is_scalar($query[$parameter])) {
                $back[$parameter] = (string) $query[$parameter];
            }
        }
        if ($action === 'batch.create') {
            $back = ['company' => $chosen, 'view' => 'batches', 'id' => $_SESSION['flash']['result']['id']];
        }
        WebResponse::redirect($back);
    }
    if ($actor !== null) {
        $receipt = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        if ($view === 'settings') {
            $result = $workbench->settings($actor);
        } elseif ($view !== 'home') {
            $section = Workbench::NAVIGATION[$view];
            $result = (new Queries($db))->read($actor, $chosen, $view, $query);
            if ($view === 'batches' && !isset($query['id'])) {
                $result['kinds'] = (new BatchJobs($db))->availableKinds($actor, $chosen);
                $result['kind'] = Input::text($query['kind'] ?? array_key_first($result['kinds']), '任务类型', 32);
                $result['candidates'] = (new Queries($db))->read($actor, $chosen, 'batch-candidates', [
                    'kind' => $result['kind'], 'before' => $query['candidates_before'] ?? null,
                ]);
            }
            if ($view === 'accounts' && isset($query['id'])) {
                $result['contacts'] = (new Queries($db))->read($actor, $chosen, 'contacts', ['id' => $query['id']])['items'];
            }
            if ($view === 'renewals' && isset($query['id'])) {
                $outreach = new Outreach($db);
                $result['outreach'] = $outreach->related($actor, $chosen, $result['id'],
                    isset($query['outreach_after']) ? Input::id($query['outreach_after']) : 0);
                $result['contacts'] = $result['is_current'] ? $outreach->contacts($actor, $chosen, $result['id']) : [];
            }
            if ($view === 'success-contracts' && isset($query['id'], $query['snapshot'])) {
                $snapshot = (new Queries($db))->read($actor, $chosen, 'health', ['id' => $query['snapshot']]);
                if ($snapshot['agreement_id'] !== $result['id']) {
                    throw new BusinessError('未找到此合同的健康度记录。', 404);
                }
                $result['snapshot'] = $snapshot;
            }
            if ($view === 'reports' && !isset($query['id']) && $actor->has($chosen, 'manager')) {
                $result['fx_sets'] = (new Queries($db))->read($actor, $chosen, 'report-rates', [])['items'];
                $result['eligible_companies'] = [];
                foreach ($companies as $eligible) {
                    if (($eligible['id'] === $chosen || $eligible['parent_id'] === $chosen)
                        && $actor->has($eligible['id'], 'manager')) {
                        $result['eligible_companies'][$eligible['id']] = $eligible['name'];
                    }
                }
            }
        }
    }
} catch (BusinessError $failure) {
    http_response_code($failure->status);
    $result = null;
    $error = $failure->getMessage();
    if (isset($failure->details['current_version'])) {
        $error .= ' 当前版本 ' . (int) $failure->details['current_version'] . '。';
    }
    if (isset($failure->details['owner']['display_name'])) {
        $error .= ' 当前负责人 ' . $failure->details['owner']['display_name'] . '。';
    }
} catch (Throwable $failure) {
    http_response_code(503);
    $result = null;
    $error = '服务暂时不可用。请稍后重试。';
    error_log('Customer Suite workbench: ' . $failure::class . ' ' . $failure->getCode());
}

$csrf = $session?->csrf() ?? '';
require dirname(__DIR__) . '/templates/layout.php';
