<?php

declare(strict_types=1);

use CustomerSuite\BatchJobs;
use CustomerSuite\Outreach;
use CustomerSuite\Workbench;

$base = ['company' => $chosen, 'view' => 'batches'];
?>
<?php if (!isset($query['id'])): ?>
    <section class="panel table-panel">
        <h2>创建任务</h2>
        <nav class="filters" aria-label="任务类型">
            <?php foreach ($result['kinds'] as $kind => $label): ?>
                <a href="<?= e(url($base + ['kind' => $kind])) ?>" <?= $kind === $result['kind'] ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($result['candidates']['items'] === []): ?>
            <p class="empty">暂无可办理的记录。</p>
        <?php else: ?>
            <?php operation($csrf, 'batch.create', '创建任务', static function () use ($result): void { ?>
                <?php if ($result['kind'] === 'lead.reassign'): ?>
                    <?php field('owner_id', '新销售负责人编号（留空回收到公海）', 'number', false); ?>
                    <?php field('reason', '分配或回收原因'); ?>
                <?php endif; ?>
                <div class="table-scroll full"><table>
                    <thead><tr><th scope="col">选择原记录</th><th scope="col">状态</th><th scope="col">版本</th></tr></thead><tbody>
                    <?php foreach ($result['candidates']['items'] as $item): ?>
                        <?php $label = $item['label'] ?? (Outreach::CHANNELS[$item['channel']] . ' · 续约 #' . $item['renewal_id'] . ' · ' . $item['cycle_ends_on']); ?>
                        <tr><td><label class="batch-selection"><input type="checkbox" name="data[items][]" value="<?= e($item['id'] . ':' . $item['version']) ?>">
                            #<?= e($item['id']) ?> · <?= e($label) ?></label></td>
                            <td><?= e(Workbench::STATES[$item['state']] ?? $item['state']) ?></td><td>v<?= e($item['version']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
            <?php }, ['kind' => $result['kind']]); ?>
        <?php endif; ?>
        <?php if ($result['candidates']['before']): ?>
            <p><a href="<?= e(url($base + ['kind' => $result['kind'], 'candidates_before' => $result['candidates']['before']])) ?>">查看较早的候选记录</a></p>
        <?php endif; ?>
    </section>
    <section class="panel table-panel">
        <h2>任务历史</h2>
        <?php if ($result['items'] === []): ?><p class="empty">暂无已提交的批量任务。</p><?php else: ?>
            <div class="table-scroll"><table><thead><tr><th scope="col">任务</th><th scope="col">提交人</th>
                <th scope="col">状态</th><th scope="col">完成 / 选中</th><th scope="col">创建时间（UTC）</th></tr></thead><tbody>
                <?php foreach ($result['items'] as $batch): ?>
                    <tr><td><a href="<?= e(url($base + ['id' => $batch['id']])) ?>">#<?= e($batch['id']) ?> · <?= e(BatchJobs::KINDS[$batch['kind']]) ?></a></td>
                        <td><?= e($batch['display_name']) ?></td><td><?= e(BatchJobs::STATES[$batch['state']]) ?></td>
                        <td><?= e($batch['counts']['completed']) ?> / <?= e($batch['total']) ?></td><td><?= e($batch['created_at']) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <?php if ($result['before']): ?>
            <a class="pager" href="<?= e(url($base + ['kind' => $result['kind'], 'before' => $result['before']])) ?>">查看较早的任务</a>
        <?php endif; ?>
    </section>
<?php else: ?>
    <a class="pager" href="<?= e(url($base + ['kind' => $result['kind']])) ?>">← 返回任务列表</a>
    <section class="panel table-panel">
        <h2>任务 #<?= e($result['id']) ?> · <?= e(BatchJobs::KINDS[$result['kind']]) ?></h2>
        <p><?= e(BatchJobs::STATES[$result['state']]) ?> · <?= e($result['display_name']) ?> · <?= e($result['created_at']) ?> UTC</p>
        <?php if ($result['kind'] === 'lead.reassign'): ?>
            <p><?= isset($result['parameters']['owner_id']) ? '目标负责人 #' . e($result['parameters']['owner_id']) : '回收到公海' ?>
                · <?= e($result['parameters']['reason']) ?></p>
        <?php endif; ?>
        <p><?php foreach ($result['counts'] as $state => $count): ?>
            <?php if ($count > 0): ?><span class="batch-count"><?= e(BatchJobs::STATES[$state]) ?> <?= e($count) ?></span><?php endif; ?>
        <?php endforeach; ?></p>
        <a href="<?= e(url($base + ['id' => $result['id']])) ?>">刷新任务状态</a>
    </section>
    <section class="panel table-panel">
        <h2>选中记录与处理结果</h2>
        <?php $retryable = array_column(array_filter($result['items'], static fn (array $item): bool =>
            $item['can_open'] && in_array($item['state'], ['retryable', 'waiting_external'], true)), 'version', 'id'); ?>
        <?php $rows = static function () use ($result, $base, $retryable): void { ?>
            <div class="table-scroll full"><table><thead><tr><th scope="col">原记录</th><th scope="col">选中版本</th>
                <th scope="col">状态</th><th scope="col">处理结果</th><th scope="col">尝试次数</th>
                <?php if ($retryable !== []): ?><th scope="col">重试</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php $lead = $result['kind'] === 'lead.reassign';
                $source = $lead ? ['view' => 'leads', 'id' => $item['subject_id']]
                    : ['view' => 'renewals', 'id' => $item['renewal_id'], 'outreach_after' => $item['subject_id'] > 1 ? $item['subject_id'] - 1 : null];
                $record = ($lead ? '线索 #' : '触达 #') . $item['subject_id']; ?>
                <tr><td><?php if ($item['can_open']): ?>
                        <a href="<?= e(url(['company' => $base['company']] + $source) . ($lead ? '' : '#outreach-' . $item['subject_id'])) ?>"><?= e($record) ?></a>
                    <?php else: ?><?= e($record) ?><?php endif; ?></td>
                    <td>v<?= e($item['selected_version']) ?></td><td><?= e(BatchJobs::STATES[$item['state']]) ?></td>
                    <td><?= e($item['reason'] ?? ($item['state'] === 'completed' ? '结果 v' . $item['subject_version'] : '—')) ?></td>
                    <td><?= e($item['attempts']) ?></td>
                    <?php if ($retryable !== []): ?><td>
                        <?php if (isset($retryable[$item['id']])): ?>
                            <input type="checkbox" name="data[items][]" aria-label="重试<?= e($record) ?>" value="<?= e($item['id'] . ':' . $item['version']) ?>">
                        <?php endif; ?>
                    </td><?php endif; ?></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php };
        if ($retryable === []) {
            $rows();
        } else {
            operation($csrf, 'batch.retry', '重试选中条目', $rows, ['id' => $result['id']]);
        } ?>
    </section>
    <section class="panel table-panel">
        <h2>处理记录</h2>
        <ul>
            <?php foreach ($result['timeline'] as $event): ?>
                <?php $action = match ($event['action']) {
                    'batch.create' => '创建任务',
                    'batch.retry' => '重新排队 ' . count($event['details']['items']) . ' 条',
                    'batch.item' => '条目 #' . $event['details']['item_id'] . ' · 第 ' . $event['details']['attempt']
                        . ' 次办理 · ' . BatchJobs::STATES[$event['details']['state']],
                }; ?>
                <li><?= e($event['occurred_at']) ?> UTC · <?= e($event['actor_name']) ?> · <?= e($action) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($result['timeline']) === 100): ?>
            <a href="<?= e(url($base + ['id' => $result['id'], 'after' => $result['timeline'][99]['id']])) ?>">查看更多处理记录</a>
        <?php endif; ?>
    </section>
<?php endif; ?>
