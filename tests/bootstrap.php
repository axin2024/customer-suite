<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Database;
use CustomerSuite\Migrations;

require dirname(__DIR__) . '/src/bootstrap.php';

if (getenv('CRM_TEST_DATABASE') !== '1') {
    fwrite(STDERR, "Set CRM_TEST_DATABASE=1 and use a disposable MySQL database.\n");
    exit(2);
}

$db = Database::connect();
$schema = $db->row('SELECT DATABASE() AS name')['name'];
if (!str_starts_with($schema, 'crm_test')) {
    throw new RuntimeException('Test databases must have a name starting with crm_test.');
}
(new Migrations($db, dirname(__DIR__) . '/database/migrations'))->migrate();
$assertions = 0;

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . "\nExpected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    global $assertions;
    $assertions++;
}

function rejects(int $status, callable $work, string $label): BusinessError
{
    try {
        $work();
    } catch (BusinessError $error) {
        same($status, $error->status, $label);
        return $error;
    }
    throw new RuntimeException($label . ' succeeded unexpectedly.');
}

function done(string $name): void
{
    global $assertions;
    printf("%s: %d assertions passed.\n", $name, $assertions);
}
