<?php

declare(strict_types=1);

use CustomerSuite\HealthSnapshots;
use CustomerSuite\Renewals;
use CustomerSuite\Workbench;

$base = ['company' => $chosen, 'view' => $view];
$contractLink = ['company' => $chosen, 'view' => 'success-contracts'];
$renewalLink = ['company' => $chosen, 'view' => 'renewals'];
$manager = $actor->has($chosen, 'manager');
$detail = isset($query['id']);
$isRenewal = $view === 'renewals';
$state = static fn (string $value): string => Workbench::STATES[$value] ?? $value;
$renewalRows = static function (array $rows) use ($state, $renewalLink): void {
    if ($rows === []) {
        echo '<p class="empty">暂无续约记录</p>';
        return;
    }
    ?>
    <div class="table-scroll"><table><thead><tr><th scope="col">合同</th><th scope="col">客户</th><th scope="col">状态</th>
        <th scope="col">到期日</th><th scope="col">下次跟进</th><th scope="col">负责人</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><a href="<?= e(url($renewalLink + ['id' => $row['id']])) ?>"><?= e($row['reference_no']) ?> · #<?= e($row['id']) ?></a></td>
            <td><?= e($row['legal_name']) ?></td><td><?= e($state($row['state'])) ?></td><td><?= e($row['cycle_ends_on']) ?></td>
            <td><?= e($row['next_follow_up'] ?? '—') ?></td><td>#<?= e($row['owner_id']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php
};
?>
<?php if (!$detail && $isRenewal): ?>
    <nav class="filters" aria-label="续约状态">
        <a href="<?= e(url($base)) ?>" <?= !isset($query['state']) ? 'aria-current="true"' : '' ?>>全部</a>
        <?php foreach (Renewals::STATES as $status): ?>
            <a href="<?= e(url($base + ['state' => $status])) ?>" <?= ($query['state'] ?? null) === $status ? 'aria-current="true"' : '' ?>>
                <?= e($state($status)) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($manager): ?>
        <?php formPanel('扫描到期合同', $csrf, 'renewal.scan', '开始扫描', static function (): void {
            field('as_of', '窗口起始日（UTC）', 'date', true, gmdate('Y-m-d'));
            field('window_days', '向后天数（1–365）', 'number', true, 30);
        }); ?>
    <?php endif; ?>
    <section class="panel table-panel"><?php $renewalRows($result['items']); ?></section>
<?php elseif (!$detail): ?>
    <section class="panel table-panel">
        <?php if ($result['items'] === []): ?><p class="empty">暂未分配客户成功合同</p><?php else: ?>
        <div class="table-scroll"><table><thead><tr><th scope="col">合同编号</th><th scope="col">签约客户</th><th scope="col">到期日</th>
            <th scope="col">合同状态</th><th scope="col">客户成功负责人</th></tr></thead><tbody>
        <?php foreach ($result['items'] as $row): ?>
            <tr><td><a href="<?= e(url($base + ['id' => $row['id']])) ?>"><?= e($row['reference_no']) ?></a></td>
                <td><?= e($row['legal_name']) ?></td><td><?= e($row['ends_on']) ?></td><td><?= e($state($row['state'])) ?></td>
                <td><?= e($row['owner_name'] ?? '待分配') ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
<?php elseif ($isRenewal): ?>
    <?php require __DIR__ . '/renewal-record.php'; ?>
<?php else: ?>
    <a class="pager" href="<?= e(url($base)) ?>">← 返回客户成功</a>
    <section class="panel table-panel">
        <h2><?= e($result['reference_no']) ?> · <?= e($result['legal_name']) ?></h2>
        <p>有效合同 v<?= e($result['active_revision']) ?> · <?= e($state($result['state'])) ?> · 到期 <?= e($result['ends_on']) ?></p>
        <p>客户成功负责人 <?= $result['owner_id'] === null ? '待分配' : '#' . e($result['owner_id']) ?></p>
        <?php if ($result['account_state'] === 'frozen'): ?>
            <p class="notice">当前客户有风险。客户已冻结，自动触达暂停。</p>
        <?php endif; ?>
    </section>
    <?php if ($manager): ?>
        <?php formPanel('分配客户成功负责人', $csrf, 'success.assign', '保存分配', static function (): void {
            field('owner_id', '客户成功人员编号', 'number');
            field('reason', '分配或交接依据');
        }, ['agreement_id' => $result['id'], 'agreement_version' => $result['version'],
            'assignment_version' => $result['assignment_version'] ?: '']); ?>
    <?php endif; ?>
    <?php if ($result['owner_id'] !== null): ?>
        <?php formPanel('采集健康度快照', $csrf, 'success.health', '采集当前健康度', static function (): void {
        }, ['agreement_id' => $result['id']]); ?>
    <?php endif; ?>
    <section class="panel table-panel">
        <h2>健康度历史</h2>
        <?php if ($result['health'] === []): ?><p class="empty">尚未采集快照</p><?php endif; ?>
        <?php foreach ($result['health'] as $snapshot): ?>
            <p><a href="<?= e(url($base + ['id' => $result['id'], 'snapshot' => $snapshot['id']])) ?>">
                <?= e($snapshot['updated_at']) ?> UTC · <?= e($snapshot['score']) ?> 分 · <?= e(HealthSnapshots::BANDS[$snapshot['band']]) ?>
                · 合同 v<?= e($snapshot['agreement_revision']) ?></a></p>
        <?php endforeach; ?>
        <?php if (isset($result['snapshot'])): ?>
            <?php $selected = $result['snapshot']; ?>
            <h3>快照 #<?= e($selected['id']) ?> · 评分模型 v<?= e($selected['model_version']) ?> · <?= e($selected['captured_by_name']) ?></h3>
            <ul>
                <?php foreach ($selected['signals'] as $signal): ?>
                    <?php $summary = match ($signal['kind']) {
                        'account' => '客户 #' . $signal['id'] . ' v' . $signal['version'] . ' · ' . $state($signal['state']),
                        'agreement' => '合同 v' . $signal['revision'] . ' · ' . $state($signal['state']) . ' · 距到期 ' . $signal['days_remaining'] . ' 日',
                        'service' => '未解决工单 ' . $signal['open_count'] . ' · 已升级 ' . $signal['escalated_count'],
                        'delivery' => '未解决交付问题 ' . $signal['open_count'],
                    }; ?>
                    <li><?= e($summary) ?> · 扣 <?= e($signal['deduction']) ?> 分</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="panel table-panel"><h2>续约记录</h2><?php $renewalRows($result['renewals']); ?></section>
<?php endif; ?>
<?php if (!$detail && ($result['next_cursor'] ?? null)): ?>
    <a class="pager" href="<?= e(url($base + ['state' => $query['state'] ?? null, 'cursor' => $result['next_cursor']])) ?>">下一页</a>
<?php endif; ?>
