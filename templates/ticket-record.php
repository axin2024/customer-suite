<?php

declare(strict_types=1);

$clockLabels = ['response' => '首次响应', 'resolution' => '解决'];
$actions = match ($result['state']) {
    'open' => ['wait' => '等待客户'],
    'waiting' => ['resume' => '恢复处理'],
    'resolved' => ['close' => '关闭工单'],
    default => [],
};
if (in_array($result['state'], ['open', 'waiting'], true)) {
    $actions += $result['responded_at'] === null ? ['respond' => '登记首次响应'] : ['resolve' => '登记解决'];
}
?>
<section class="panel table-panel"><h2>来单与合同依据</h2>
    <p><?= e($result['description']) ?></p>
    <?php $rowsTable([$result], ['reference_no' => '签约编号', 'agreement_revision' => '合同版本', 'received_at' => '接收时间（UTC）',
        'responded_at' => '首次响应（UTC）', 'resolved_at' => '解决时间（UTC）', 'closed_at' => '关闭时间（UTC）', 'close_reason' => '关闭依据']); ?>
    <?php if ($result['source'] !== null): ?>
        <?php $rowsTable([$result['source']], ['source' => '来源', 'external_id' => '来源编号']); ?>
    <?php endif; ?>
</section>
<?php if ($result['calendar'] !== null): ?>
    <section class="panel table-panel"><h2>服务时钟</h2>
        <p><?= e($result['calendar']['timezone']) ?> · 日历第 <?= e($result['calendar']['revision']) ?> 版 · 规则第 <?= e($result['policy_revision']) ?> 版</p>
        <?php if ($result['pause_reason'] !== null): ?><p>暂停依据 · <?= e($result['pause_reason']) ?></p><?php endif; ?>
        <?php $clockRows = []; foreach ($result['clocks'] as $kind => $clock) { $clockRows[] = ['clock' => $clockLabels[$kind]] + $clock; } ?>
        <?php $rowsTable($clockRows, ['clock' => '时钟', 'state' => '状态', 'target_minutes' => '约定分钟', 'elapsed_minutes' => '已用分钟',
            'remaining_minutes' => '剩余分钟', 'deadline_at' => '时限（UTC）']); ?>
        <?php if ($result['escalation'] !== null): ?>
            <p>已升级 · <?= e($clockLabels[$result['escalation']['clock_kind']]) ?>超时 · <?= e($result['escalation']['detected_at']) ?> UTC</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php if ($result['state'] === 'review' && $manager): ?>
    <?php formPanel('复核服务资格', $csrf, 'ticket.review', '保存复核结论', static function (): void {
        selectField('decision', '复核决定', ['recheck' => '重查当前合同和配置', 'close' => '关闭并保留来单']);
        field('reason', '复核依据');
    }, $idVersion); ?>
<?php endif; ?>
<?php if ($result['can_edit'] && in_array($result['state'], ['open', 'waiting'], true)): ?>
    <?php if ($manager): ?>
        <?php formPanel('派发或转队列', $csrf, 'ticket.assign', '保存派发', static function (): void {
            field('owner_id', '服务人员编号（留空回收）', 'number', false);
            field('queue_id', '服务队列编号（留空保留）', 'number', false);
            field('reason', '派发依据');
        }, $idVersion); ?>
    <?php elseif ($result['owner_id'] === null): ?>
        <?php formPanel('认领服务工单', $csrf, 'ticket.assign', '认领给我', static function (): void {
            field('reason', '认领说明');
        }, $idVersion + ['owner_id' => $actor->userId]); ?>
    <?php endif; ?>
<?php endif; ?>
<?php if ($result['can_edit'] && $actions): ?>
    <?php formPanel('记录处理进度', $csrf, 'ticket.advance', '提交记录', static function () use ($actions): void {
        selectField('action', '操作', $actions);
        field('reason', '处理说明');
    }, $idVersion); ?>
<?php endif; ?>
<section class="panel table-panel"><h2>办理记录</h2>
    <?php $rowsTable($result['notes'], ['display_name' => '登记人', 'created_at' => '登记时间（UTC）', 'body' => '内容']); ?>
</section>
<?php if ($result['can_edit'] && $result['state'] !== 'closed'): ?>
    <?php formPanel('追加办理记录', $csrf, 'ticket.note', '保存记录', static function (): void { field('body', '办理记录'); }, $idVersion); ?>
<?php endif; ?>
