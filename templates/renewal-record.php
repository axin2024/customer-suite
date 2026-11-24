<?php

declare(strict_types=1);

use CustomerSuite\Outreach;
use CustomerSuite\Renewals;

$renewalVersion = ['id' => $result['id'], 'version' => $result['version']];
?>
<a class="pager" href="<?= e(url($base)) ?>">← 返回续约工作台</a>
<section class="panel table-panel">
    <h2><?= e($result['reference_no']) ?> · <?= e($result['legal_name']) ?></h2>
    <p><a href="<?= e(url($contractLink + ['id' => $result['agreement_id']])) ?>">客户成功合同 #<?= e($result['agreement_id']) ?></a>
        · 原始合同 v<?= e($result['agreement_revision']) ?>
        · 商机 #<?php if ($manager): ?><a href="<?= e(url(['company' => $chosen, 'view' => 'opportunities', 'id' => $result['opportunity_id']])) ?>"><?= e($result['opportunity_id']) ?></a>
        <?php else: ?><?= e($result['opportunity_id']) ?><?php endif; ?></p>
    <p><?= e($state($result['state'])) ?> · 到期 <?= e($result['cycle_ends_on']) ?> · 下次跟进 <?= e($result['next_follow_up'] ?? '—') ?></p>
    <?php if ($result['outcome_code'] !== null): ?>
        <p><?= e(Renewals::OUTCOMES[$result['state']][$result['outcome_code']] ?? '合同到期日变更') ?> · <?= e($result['outcome_reason']) ?></p>
        <?php if ($result['evidence_ref'] !== null): ?><p>签署凭据 <?= e($result['evidence_ref']) ?></p><?php endif; ?>
    <?php elseif (!$result['is_current']): ?><p class="notice">合同到期日或状态已变化，请核对有效合同。</p><?php endif; ?>
</section>
<?php if ($result['is_current']): ?>
    <?php formPanel('安排续约跟进', $csrf, 'renewal.follow-up', '保存跟进', static function () use ($result): void {
        field('next_follow_up', '下次跟进日', 'date', true, $result['next_follow_up']);
        field('reason', '跟进计划或本次记录');
    }, $renewalVersion); ?>
    <?php formPanel('登记续约成功', $csrf, 'renewal.decide', '确认续约成功', static function (): void {
        field('evidence_ref', '签署凭据编号');
        field('reason', '结果说明');
    }, $renewalVersion + ['outcome' => 'renewed', 'code' => 'signed']); ?>
    <?php formPanel('登记流失原因', $csrf, 'renewal.decide', '确认流失', static function (): void {
        selectField('code', '原因分类', Renewals::OUTCOMES['lost']);
        field('reason', '结果说明');
    }, $renewalVersion + ['outcome' => 'lost']); ?>
    <?php if ($result['contacts'] !== []): ?>
        <?php formPanel('排队触达联系人', $csrf, 'outreach.queue', '加入触达队列', static function () use ($result): void {
            $contacts = [];
            foreach ($result['contacts'] as $contact) {
                $channels = [];
                foreach (Outreach::CHANNELS as $channel => $name) {
                    if ($contact[$channel . '_allowed']) {
                        $channels[] = $name;
                    }
                }
                $contacts[$contact['id']] = $contact['name'] . ' · ' . ($channels === [] ? '尚无渠道同意' : implode('、', $channels) . '已同意');
            }
            selectField('contact_id', '联系人', $contacts);
            selectField('channel', '发送渠道', Outreach::CHANNELS);
            textAreaField('message', '触达内容');
        }, $renewalVersion); ?>
    <?php endif; ?>
<?php endif; ?>
<section class="panel table-panel"><h2>触达队列与回执</h2>
    <a class="pager" href="<?= e(url(['company' => $chosen, 'view' => 'batches', 'kind' => 'outreach.process'])) ?>">批量处理续约触达</a>
    <?php if ($result['outreach'] === []): ?><p class="empty">暂无触达记录</p><?php endif; ?>
    <?php foreach ($result['outreach'] as $job): ?>
        <article id="outreach-<?= e($job['id']) ?>">
            <h3>#<?= e($job['id']) ?> · <?= e(Outreach::CHANNELS[$job['channel']]) ?> · <?= e($state($job['state'])) ?></h3>
            <p>联系人 #<?= e($job['contact_id']) ?> · 渠道提交 <?= e($job['attempts']) ?> 次
                <?php if ($job['block_reason']): ?> · <?= e(Outreach::BLOCKS[$job['block_reason']]) ?><?php endif; ?>
                <?php if ($job['last_error']): ?> · <?= e(Outreach::ERRORS[$job['last_error']]) ?><?php endif; ?>
                <?php if ($job['provider_reference']): ?> · 回执 <?= e($job['provider_reference']) ?><?php endif; ?></p>
            <p><?= e($job['message']) ?></p>
            <?php if (in_array($job['state'], ['queued', 'blocked', 'retryable'], true)): ?>
                <?php $workVersion = ['id' => $job['id'], 'version' => $job['version']]; ?>
                <?php formPanel('核验授权并处理触达', $csrf, 'outreach.process', '处理触达', static function (): void {
                }, $workVersion); ?>
                <?php formPanel('取消触达', $csrf, 'outreach.cancel', '确认取消', static function (): void {
                    field('reason', '取消原因');
                }, $workVersion); ?>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if (count($result['outreach']) === 100): ?>
        <a class="pager" href="<?= e(url($base + ['id' => $result['id'], 'outreach_after' => $result['outreach'][99]['id']])) ?>">查看更多触达记录</a>
    <?php endif; ?>
</section>
<section class="panel table-panel"><h2>续约时间线</h2>
    <div class="table-scroll"><table><thead><tr><th scope="col">UTC 时间</th><th scope="col">操作人</th><th scope="col">操作</th>
        <th scope="col">记录</th></tr></thead><tbody>
    <?php foreach ($result['timeline'] as $event): ?>
        <tr><td><?= e($event['occurred_at']) ?></td><td><?= e($event['actor_name']) ?></td><td><?= e($event['action']) ?></td>
            <td><?= e($event['details']['reason'] ?? ($event['details']['state'] ?? '—')) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if (count($result['timeline']) === 100): ?>
        <a class="pager" href="<?= e(url($base + ['id' => $result['id'], 'after' => $result['timeline'][99]['id']])) ?>">查看更多时间线</a>
    <?php endif; ?>
</section>
