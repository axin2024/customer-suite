<?php

declare(strict_types=1);

$reviewFields = $idVersion + ['kind' => $view === 'quotes' ? 'quote' : 'agreement'];
?>
<?php if ($reviewAssignment !== null): ?>
    <section class="panel table-panel"><h2>审批委托</h2>
        <?php $rowsTable([array_replace($reviewAssignment, ['state' => match ($reviewAssignment['state']) {
            'assigned' => '待代办', 'used' => '已代办', 'revoked' => '已撤回', 'superseded' => '单据已通过其他审批处理', default => '已失效',
        }])], ['state' => '状态', 'granted_by' => '委托人编号', 'delegate_id' => '代办人编号', 'expires_at' => '有效期（UTC）']); ?>
    </section>
    <?php if ($reviewAssignment['can_revoke']): ?>
        <?php formPanel('撤回委托', $csrf, 'review.revoke', '撤回委托', static function (): void {},
            $reviewFields + ['delegation_version' => $reviewAssignment['delegation_version']]); ?>
    <?php endif; ?>
<?php endif; ?>
<?php if ($result['delegation_candidates']): ?>
    <?php formPanel('指定审批代办', $csrf, 'review.assign', '委托当前版本', static function () use ($result): void {
        selectField('delegate_id', '代办人', $result['delegation_candidates']);
        field('hours', '有效小时数（1–168）', 'number', true, 24);
    }, $reviewFields); ?>
<?php endif; ?>
