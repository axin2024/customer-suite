<?php

declare(strict_types=1);

use CustomerSuite\Database;
use CustomerSuite\Migrations;

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $ready = (new Migrations(Database::connect(), dirname(__DIR__) . '/database/migrations'))->ready();
} catch (Throwable) {
    $ready = false;
}
http_response_code($ready ? 200 : 503);
echo $ready ? '{"status":"ready"}' : '{"status":"unavailable"}';
