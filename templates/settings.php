<?php

declare(strict_types=1);

use CustomerSuite\Access;

formPanel('新增公司', $csrf, 'admin.company', '创建公司', static function (): void {
    field('code', '公司代码');
    field('name', '公司名称');
    field('parent_id', '直属上级公司编号', 'number', false);
});

formPanel('新增人员', $csrf, 'admin.user', '创建人员', static function (): void {
    field('name', '姓名');
    field('email', '邮箱', 'email');
    field('password', '初始密码', 'password');
});

formPanel('调整人员岗位', $csrf, 'admin.membership', '保存岗位', static function () use ($companies): void {
    field('user_id', '人员编号', 'number');
    selectField('company_id', '公司', array_column($companies, 'name', 'id'));
    selectField('role', '岗位', array_combine(Access::ROLES, ['经理', '市场', '销售', '实施', '服务', '客户成功', '审批', '计量对接', '财务', '客户管理员']));
    selectField('operation', '操作', ['grant' => '授予', 'revoke' => '撤回']);
});
?>
<section class="panel table-panel">
    <h2>公司</h2>
    <table><thead><tr><th>编号</th><th>名称</th><th>代码</th><th>直属上级编号</th></tr></thead>
        <tbody><?php foreach ($companies as $item): ?>
            <tr><td><?= e($item['id']) ?></td><td><?= e($item['name']) ?></td><td><?= e($item['code']) ?></td><td><?= e($item['parent_id'] ?? '—') ?></td></tr>
        <?php endforeach; ?></tbody></table>
</section>
<section class="panel table-panel">
    <h2>人员与岗位</h2>
    <table><thead><tr><th>编号</th><th>姓名</th><th>邮箱</th><th>岗位</th></tr></thead>
        <tbody><?php foreach ($result as $person): ?>
            <tr><td><?= e($person['id']) ?></td><td><?= e($person['display_name']) ?></td><td><?= e($person['email']) ?></td>
                <td><?= e($person['is_admin'] ? '系统管理员' : ($person['roles'] ?? '未分配')) ?></td></tr>
        <?php endforeach; ?></tbody></table>
</section>
