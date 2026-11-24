<?php

declare(strict_types=1);

use CustomerSuite\Workbench;

require __DIR__ . '/functions.php';
$title = $section[0] ?? ($view === 'settings' ? '组织与权限' : '工作台');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Customer Suite</title>
    <link rel="stylesheet" href="/assets/workbench.css">
    <script src="/assets/workbench.js" defer></script>
</head>
<body>
<?php if ($actor === null): ?>
    <main class="login">
        <section class="login-card">
            <div class="brand"><span class="brand-mark" aria-hidden="true">C</span><span>Customer Suite</span></div>
            <h1>登录客户运营工作台</h1>
            <?php if ($error): ?><p role="alert" class="notice error"><?= e($error) ?></p><?php endif; ?>
            <?php if ($session !== null): ?>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <label>工作邮箱<input type="email" name="email" autocomplete="username" required autofocus></label>
                    <label>密码<input type="password" name="password" autocomplete="current-password" required></label>
                    <button class="primary" type="submit">登录</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
<?php else: ?>
    <div class="shell">
        <aside class="rail">
            <a class="brand" href="<?= e(url(['company' => $chosen])) ?>"><span class="brand-mark" aria-hidden="true">C</span><span>Customer Suite</span></a>
            <form class="company-picker" method="get">
                <label for="company">工作公司</label>
                <div class="switch">
                    <select id="company" name="company" <?= $companies === [] ? 'disabled' : '' ?>>
                        <?php if ($companies === []): ?><option>暂无可用公司</option><?php endif; ?>
                        <?php foreach ($companies as $item): ?>
                            <option value="<?= e($item['id']) ?>" <?= $chosen === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($companies !== []): ?><button type="submit">切换</button><?php endif; ?>
                </div>
            </form>
            <nav aria-label="工作台导航">
                <a href="<?= e(url(['company' => $chosen])) ?>" <?= $view === 'home' ? 'aria-current="page"' : '' ?>>工作台</a>
                <?php foreach ($links as $key => $label): ?>
                    <a href="<?= e(url(['company' => $chosen, 'view' => $key])) ?>"
                       <?= $view === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <main class="workspace">
            <header class="topbar">
                <span><?= e($company['name'] ?? '系统管理') ?></span>
                <div class="session"><span><?= e($viewer['display_name'] ?? '') ?></span>
                    <form method="post"><input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button>退出</button></form>
                </div>
            </header>
            <section class="content">
                <div class="heading">
                    <h1><?= e($title) ?></h1>
                    <?php if ($section !== null): ?><p><?= e($section[1]) ?></p><?php endif; ?>
                </div>
                <?php if ($error): ?><p role="alert" class="notice error"><?= e($error) ?></p><?php endif; ?>
                <?php if ($receipt): ?>
                    <p role="status" class="notice">操作<?= $receipt['replayed'] ? '已按原回执返回' : '已保存' ?>
                        <?php if (isset($receipt['result']['id'])): ?> · 记录 #<?= e($receipt['result']['id']) ?><?php endif; ?>
                        <?php if (isset($receipt['result']['version'])): ?> · v<?= e($receipt['result']['version']) ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if ($view === 'home'): ?>
                    <?php if ($links === []): ?><p class="empty">当前账户暂无可操作的公司，请联系管理员分配岗位。</p><?php endif; ?>
                    <div class="launch-grid">
                        <?php foreach ($links as $key => $label): ?>
                            <a class="launch panel" href="<?= e(url(['company' => $chosen, 'view' => $key])) ?>">
                                <strong><?= e($label) ?></strong><span><?= e(Workbench::NAVIGATION[$key][1] ?? '配置组织、岗位和人员') ?></span>
                                <span class="arrow" aria-hidden="true">↗</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($view === 'settings' && $result !== null): ?>
                    <?php require __DIR__ . '/settings.php'; ?>
                <?php elseif ($result !== null): ?>
                    <?php require __DIR__ . '/records.php'; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
