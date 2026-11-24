<?php

declare(strict_types=1);

use CustomerSuite\Agreements;
use CustomerSuite\Input;
use CustomerSuite\ServiceSettings;

$office = [['09:00', '12:00'], ['13:00', '18:00']];
$limits = ['P1' => ['response' => 15, 'resolution' => 60], 'P2' => ['response' => 30, 'resolution' => 120],
    'P3' => ['response' => 90, 'resolution' => 360], 'P4' => ['response' => 120, 'resolution' => 480]];
$queues = array_column($result['queues'], 'name', 'id');
$calendars = [];
foreach ($result['calendars'] as $record) {
    $calendars[$record['id']] = $record['name'] . ' · 第 ' . $record['revision'] . ' 版';
}
formPanel('登记工作日历版本', $csrf, 'service.calendar', '保存日历版本', static function () use ($office): void {
    field('code', '日历代码', 'text', true, 'CN-OFFICE');
    field('name', '日历名称', 'text', true, '中国工作日');
    field('timezone', 'IANA 时区', 'text', true, 'Asia/Shanghai');
    textAreaField('week', '每周时段（JSON，周一=1）', Input::json(array_fill_keys([1, 2, 3, 4, 5], $office)));
    textAreaField('exceptions', '例外日期（JSON，休假用 []）', '{}');
});
if ($calendars) {
    formPanel('发布服务级别规则', $csrf, 'service.policy', '保存 SLA 版本', static function () use ($calendars, $limits): void {
        selectField('service_level', '合同服务级别', Agreements::LEVELS);
        selectField('calendar_id', '工作日历版本', $calendars);
        field('effective_from', '生效时间（带时区）', 'text', true, gmdate('Y-m-d\TH:i:s\Z'));
        textAreaField('limits', '优先级时限（JSON，单位工作分钟）', Input::json($limits));
        selectField('pause_for_customer', '等待客户时暂停计时', ['1' => '暂停', '0' => '继续']);
    });
}
formPanel('新增服务队列', $csrf, 'service.queue', '保存队列', static function (): void {
    field('code', '队列代码');
    field('name', '队列名称');
});
if ($queues) {
    formPanel('配置分类派发', $csrf, 'service.route', '保存派发规则', static function () use ($queues): void {
        selectField('category', '工单分类', ServiceSettings::CATEGORIES);
        selectField('queue_id', '受理队列', $queues);
    });
}
formPanel('授权服务人员处理合同', $csrf, 'service.grant', '保存合同授权', static function (): void {
    field('agreement_id', '合同编号', 'number');
    field('user_id', '服务人员编号', 'number');
    selectField('allowed', '授权操作', ['1' => '授予', '0' => '撤回']);
});
$labels = [
    'calendars' => ['工作日历版本', ['id' => '编号', 'code' => '代码', 'name' => '名称', 'revision' => '版本', 'timezone' => '时区']],
    'policies' => ['服务级别规则', ['id' => '编号', 'service_level' => '级别', 'calendar_id' => '日历编号', 'revision' => '版本', 'effective_from' => '生效时间（UTC）']],
    'queues' => ['服务队列', ['id' => '编号', 'code' => '代码', 'name' => '队列名称']],
    'routes' => ['派发规则', ['category' => '分类', 'queue_id' => '队列编号', 'name' => '队列名称']],
    'grants' => ['合同服务授权', ['agreement_id' => '合同记录编号', 'reference_no' => '签约编号', 'user_id' => '人员编号', 'display_name' => '服务人员']],
];
?>
<?php foreach ($labels as $resource => [$label, $columns]): ?>
    <section class="panel table-panel"><h2><?= e($label) ?></h2>
        <?php if (!$result[$resource]): ?><p class="empty">暂无记录</p><?php else: ?>
            <div class="table-scroll"><table><thead><tr>
                <?php foreach ($columns as $column): ?><th scope="col"><?= e($column) ?></th><?php endforeach; ?>
            </tr></thead><tbody>
                <?php foreach ($result[$resource] as $row): ?><tr>
                    <?php foreach (array_keys($columns) as $column): ?>
                        <td><?= e(match ($column) {
                            'category' => ServiceSettings::CATEGORIES[$row[$column]],
                            'service_level' => Agreements::LEVELS[$row[$column]],
                            default => $row[$column],
                        }) ?></td>
                    <?php endforeach; ?>
                </tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
