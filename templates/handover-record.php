<?php

declare(strict_types=1);

$scope = $result['scope'];
$confirmations = $result['confirmations'];
$canPlan = $result['can_deliver'] && $result['state'] === 'executing' && isset($confirmations['delivery']);
$unfinished = array_filter($result['milestones'], static fn (array $item): bool => $item['state'] !== 'completed');
$openIssues = array_filter($result['issues'], static fn (array $item): bool => $item['resolved_at'] === null);
?>
<section class="panel table-panel"><h2>第 <?= e($scope['revision']) ?> 版交付范围</h2>
    <p><?= e($scope['scope_summary']) ?></p>
    <?php $rowsTable([$scope], ['contact_id' => '客户代表编号', 'contact_name' => '姓名', 'contact_title' => '职务', 'contact_version' => '联系人版本']); ?>
    <?php $rowsTable([$confirmations], ['sales' => '确认销售编号', 'delivery' => '确认实施人员编号']); ?>
</section>
<?php if ($result['state'] !== 'accepted'): ?>
    <?php if ($result['can_sales'] && $result['state'] === 'planning'): ?>
        <?php formPanel('调整范围与客户代表', $csrf, 'handover.revise', '保存新范围', static function () use ($scope): void {
            field('scope', '交付范围', 'text', true, $scope['scope_summary']);
            field('contact_id', '客户代表编号', 'number', true, $scope['contact_id']);
        }, $idVersion); ?>
    <?php endif; ?>
    <?php if ($result['can_sales'] && !isset($confirmations['sales'])): ?>
        <?php formPanel('销售确认交付范围', $csrf, 'handover.confirm', '确认范围', static function (): void {}, $idVersion + ['side' => 'sales']); ?>
    <?php endif; ?>
    <?php if ($result['can_deliver'] && !isset($confirmations['delivery'])): ?>
        <?php formPanel('实施确认交付范围', $csrf, 'handover.confirm', '确认范围', static function (): void {}, $idVersion + ['side' => 'delivery']); ?>
    <?php endif; ?>
    <?php if ($result['can_assign']): ?>
        <?php formPanel('改派实施负责人', $csrf, 'handover.assign', '保存改派', static function (): void {
            field('owner_id', '新实施人员编号', 'number');
            field('reason', '改派依据');
        }, $idVersion); ?>
    <?php endif; ?>
<?php endif; ?>
<section class="panel table-panel"><h2>交付里程碑</h2>
    <?php $rowsTable($result['milestones'], ['id' => '编号', 'title' => '里程碑', 'due_on' => '计划完成日', 'state' => '状态', 'completed_at' => '完成时间（UTC）']); ?>
</section>
<?php if ($canPlan): ?>
    <?php formPanel('新增里程碑', $csrf, 'handover.milestone.add', '保存里程碑', static function (): void {
        field('title', '里程碑名称');
        field('due_on', '计划完成日', 'date');
    }, $idVersion); ?>
    <?php if ($unfinished): ?>
        <?php $milestoneChoices = array_column($unfinished, 'title', 'id'); ?>
        <?php formPanel('登记延期', $csrf, 'handover.milestone.delay', '保存延期及待确认事项', static function () use ($milestoneChoices): void {
            selectField('milestone_id', '里程碑', $milestoneChoices);
            field('due_on', '新完成日期', 'date');
            field('impact', '延期影响');
            field('pending_item', '需要确认的事项');
        }, $idVersion); ?>
        <?php formPanel('完成里程碑', $csrf, 'handover.milestone.complete', '确认完成', static function () use ($milestoneChoices): void {
            selectField('milestone_id', '里程碑', $milestoneChoices);
        }, $idVersion); ?>
    <?php endif; ?>
<?php endif; ?>
<?php if ($result['delays']): ?>
    <section class="panel table-panel"><h2>延期影响</h2>
        <?php $rowsTable($result['delays'], ['milestone_id' => '里程碑编号', 'old_due_on' => '原完成日', 'new_due_on' => '新完成日', 'impact' => '影响', 'created_at' => '记录时间（UTC）']); ?>
    </section>
<?php endif; ?>
<section class="panel table-panel"><h2>待确认事项</h2>
    <?php $rowsTable($result['issues'], ['id' => '编号', 'title' => '待确认事项', 'delay_id' => '延期记录', 'resolution' => '处理结果', 'resolved_at' => '确认时间（UTC）']); ?>
</section>
<?php if ($canPlan): ?>
    <?php formPanel('添加待确认事项', $csrf, 'handover.issue.add', '保存事项', static function (): void { field('title', '待确认事项'); }, $idVersion); ?>
    <?php if ($openIssues): ?>
        <?php formPanel('确认处理结果', $csrf, 'handover.issue.resolve', '关闭事项', static function () use ($openIssues): void {
            selectField('issue_id', '待确认事项', array_column($openIssues, 'title', 'id'));
            field('resolution', '处理结果');
        }, $idVersion); ?>
    <?php endif; ?>
<?php endif; ?>
<?php if ($result['acceptance'] !== null): ?>
    <section class="panel table-panel"><h2>客户确认回执</h2>
        <?php $rowsTable([$result['acceptance']], ['agreement_revision' => '合同版本', 'evidence_reference' => '确认编号',
            'confirmation_at' => '客户确认时间（UTC）', 'recorded_by' => '登记人员编号', 'sha256' => 'SHA-256']); ?>
        <a href="/download.php?<?= e(http_build_query(['company' => $chosen, 'handover' => $result['id']])) ?>">下载 <?= e($result['acceptance']['file_name']) ?></a>
    </section>
<?php elseif ($canPlan && $result['milestones'] && !$unfinished && !$openIssues): ?>
    <?php formPanel('登记客户验收', $csrf, 'handover.accept', '保存签收回执', static function (): void {
        field('evidence_reference', '客户回执编号');
        field('confirmed_at', '客户确认时间（带时区）', 'text', true, gmdate('Y-m-d\TH:i:s\Z'));
        echo '<label>签收附件（PDF 或 TXT，2 MiB 内）<input type="file" name="evidence_upload" accept=".pdf,.txt" required></label>';
    }, $idVersion + ['agreement_revision' => $result['agreement_revision'], 'contact_id' => $scope['contact_id']], attachment: true); ?>
<?php endif; ?>
