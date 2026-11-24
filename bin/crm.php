<?php

declare(strict_types=1);

use CustomerSuite\BusinessError;
use CustomerSuite\Access;
use CustomerSuite\BatchJobs;
use CustomerSuite\Database;
use CustomerSuite\DemoWorkspace;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Migrations;
use CustomerSuite\Outreach;
use CustomerSuite\Renewals;
use CustomerSuite\ServiceTickets;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo "Customer Suite\n  migrate\n  health\n  init-admin <邮箱> <姓名>\n  sla-sweep <公司编号> <经理编号>\n";
    echo "  renewal-scan <公司编号> <经理编号> [窗口天数]\n  outreach-due <公司编号> <经理编号>\n";
    echo "  batch-run <公司编号> <经理编号> [条目数] [任务编号]\n";
    echo "  demo-install <演示库管理员邮箱>\n";
    echo "初始化管理员前，通过环境变量 CRM_ADMIN_PASSWORD 提供密码。\n";
    echo "导入演示前，通过环境变量 CRM_DEMO_PASSWORD 提供演示岗位密码。\n";
    exit(0);
}

try {
    $db = Database::connect();
    $migrations = new Migrations($db, dirname(__DIR__) . '/database/migrations');
    $result = match ($command) {
        'migrate' => ['applied' => $migrations->migrate()],
        'health' => ['status' => $migrations->ready() ? 'ready' : 'migration_required'],
        'init-admin' => [
            'user_id' => (new Identity($db))->bootstrap($argv[3] ?? '', $argv[2] ?? '', (string) getenv('CRM_ADMIN_PASSWORD')),
        ],
        'demo-install' => (new DemoWorkspace($db))->install($argv[2] ?? '', (string) getenv('CRM_DEMO_PASSWORD')),
        'sla-sweep' => (new ServiceTickets($db))->sweep(new Access($db, Input::id($argv[3] ?? null, '经理编号')),
            Input::id($argv[2] ?? null, '公司编号')),
        'renewal-scan' => (new Renewals($db))->scan(new Access($db, Input::id($argv[3] ?? null, '经理编号')),
            Input::id($argv[2] ?? null, '公司编号'), ['window_days' => $argv[4] ?? 30]),
        'outreach-due' => (new Outreach($db))->processDue(new Access($db, Input::id($argv[3] ?? null, '经理编号')),
            Input::id($argv[2] ?? null, '公司编号')),
        'batch-run' => (new BatchJobs($db))->run(new Access($db, Input::id($argv[3] ?? null, '经理编号')),
            Input::id($argv[2] ?? null, '公司编号'), Input::integer($argv[4] ?? 20, '条目数', 1, 50),
            isset($argv[5]) ? Input::id($argv[5], '任务编号') : null),
        default => throw new BusinessError('未知命令，请运行 php bin/crm.php help。'),
    };
    echo Input::json($result), PHP_EOL;
    exit(($result['status'] ?? 'ready') === 'ready' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
