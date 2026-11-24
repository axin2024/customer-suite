<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Database;
use CustomerSuite\Handovers;
use CustomerSuite\Input;
use CustomerSuite\WebResponse;
use CustomerSuite\WebSession;

require dirname(__DIR__) . '/src/bootstrap.php';

WebResponse::headers();
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        throw new BusinessError('仅支持下载请求。', 405);
    }
    $db = Database::connect();
    $actor = (new WebSession($db))->actor() ?? throw new BusinessError('请先登录。', 401);
    session_write_close();
    $file = (new Handovers($db))->evidence($actor, Input::id($_GET['company'] ?? null), Input::id($_GET['handover'] ?? null));
    $extension = $file['media_type'] === 'application/pdf' ? 'pdf' : 'txt';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="acceptance.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($file['file_name']));
    header('Content-Length: ' . $file['byte_length']);
    header('Content-Digest: sha-256=:' . base64_encode(hex2bin($file['sha256'])) . ':');
    echo $file['body'];
} catch (BusinessError $error) {
    WebResponse::json(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Customer Suite download: ' . $error::class . ' ' . $error->getCode());
    WebResponse::json(['error' => '附件暂不可读取。'], 500);
}
