<?php

declare(strict_types=1);

use CustomerSuite\Money;
use CustomerSuite\Workbench;

require __DIR__ . '/record-forms.php';
$stateNames = Workbench::STATES;
$baseLink = ['company' => $chosen, 'view' => $view];
$columns = match ($view) {
    'accounts' => ['id' => '编号', 'name' => '客户', 'state' => '状态', 'owner_id' => '负责人', 'version' => '版本', 'updated_at' => '更新时间'],
    'leads' => ['id' => '编号', 'name' => '线索', 'state' => '状态', 'owner_id' => '负责人', 'next_follow_up' => '下次跟进', 'version' => '版本'],
    'campaigns' => ['id' => '编号', 'code' => '代码', 'name' => '活动', 'channel' => '渠道', 'touch_count' => '触点数'],
    'opportunities' => ['id' => '编号', 'title' => '商机', 'stage' => '阶段', 'currency' => '币种', 'value_minor' => '金额',
        'probability_bps' => '胜率', 'expected_close' => '预计成交', 'version' => '版本'],
    'forecasts' => ['id' => '编号', 'label' => '快照', 'period_start' => '预计成交起始日', 'period_end' => '预计成交结束日', 'captured_at' => '创建时间'],
    'quotes' => ['id' => '编号', 'title' => '对应商机', 'state' => '状态', 'revision' => '修订', 'currency' => '币种',
        'net_minor' => '报价实收', 'discount_bps' => '折扣', 'expires_on' => '有效至（UTC）'],
    'agreements' => ['id' => '编号', 'reference_no' => '合同编号', 'legal_name' => '签约客户', 'state' => '状态',
        'currency' => '币种', 'value_minor' => '金额', 'ends_on' => '合同到期', 'service_level' => '服务级别'],
};
$renderValue = static function (array $row, string $col) use ($stateNames): string|int {
    $value = $row[$col] ?? null;
    return match ($col) {
        'state', 'stage', 'decision' => $stateNames[$value] ?? ($value ?? '—'),
        'value_minor', 'weighted_minor', 'net_minor', 'gross_minor' => $value === null ? '—' : Money::format((int) $value, $row['currency']),
        'probability_bps', 'discount_bps' => $value === null ? '—' : ($value / 100) . '%',
        'service_level' => \CustomerSuite\Agreements::LEVELS[$value] ?? '—',
        default => $value === null ? '—' : (string) $value,
    };
};
$rowsTable = static function (array $rows, array $providedColumns, ?string $linkColumn = null) use ($renderValue, $baseLink, $view): void {
    if ($rows === []) {
        echo '<p class="empty">暂无记录</p>';
        return;
    }
    echo '<div class="table-scroll"><table><thead><tr>';
    foreach ($providedColumns as $header) {
        echo '<th scope="col">', e($header), '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($providedColumns) as $col) {
            $output = e($renderValue($row, $col));
            echo '<td>';
            if ($col === $linkColumn && $view !== 'campaigns') {
                echo '<a href="', e(url($baseLink + ['id' => $row['id']])), '">', $output, '</a>';
            } else {
                echo $output;
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
};
?>
<?php if (!$detail): ?>
    <?php if (isset(Workbench::FILTERS[$view])): ?>
        <nav class="filters" aria-label="状态筛选">
            <?php foreach (Workbench::FILTERS[$view] as $i => $status): ?>
                <a href="<?= e(url($baseLink + ['state' => $status])) ?>"
                   <?= ($query['state'] ?? Workbench::FILTERS[$view][0]) === $status ? 'aria-current="true"' : '' ?>>
                    <?= e($stateNames[$status]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <section class="panel table-panel">
        <?php $rowsTable($result['items'], $columns, match ($view) {
            'opportunities', 'quotes' => 'title', 'forecasts' => 'label', 'agreements' => 'reference_no', default => 'name',
        }); ?>
    </section>
    <?php if ($result['next_cursor'] ?? null): ?>
        <a class="pager" href="<?= e(url($baseLink + ['state' => $query['state'] ?? null, 'cursor' => $result['next_cursor']])) ?>">下一页</a>
    <?php endif; ?>
<?php else: ?>
    <a class="pager" href="<?= e(url($baseLink)) ?>">← 返回列表</a>
    <section class="panel table-panel">
        <h2><?= e($result['name'] ?? $result['title'] ?? $result['label'] ?? $result['reference_no']) ?></h2>
        <?php $rowsTable([$result], $columns); ?>
    </section>
    <?php if ($view === 'accounts'): ?>
        <section class="panel table-panel"><h2>集团关系和主体标识</h2>
            <?php $rowsTable([$result], ['registration_ref' => '主体登记号', 'parent_company_id' => '集团所在公司',
                'parent_id' => '集团档案编号', 'merged_into_id' => '当前主档']); ?>
        </section>
        <section class="panel table-panel"><h2>联系人</h2>
            <?php $rowsTable($result['contacts'], ['id' => '编号', 'name' => '姓名', 'job_title' => '职位',
                'email' => '邮箱', 'phone' => '电话', 'version' => '版本']); ?>
            <?php if ($manager || ($sales && $owned)): ?>
                <p class="filters">
                    <?php foreach (['email' => '导出已授权邮箱', 'phone' => '导出已授权电话'] as $channel => $label): ?>
                        <a href="/export.php?<?= e(http_build_query(['company' => $chosen, 'account' => $result['id'], 'channel' => $channel])) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </section>
        <section class="panel table-panel"><h2>外部档案来源</h2>
            <?php $rowsTable($result['aliases'], ['source' => '来源', 'external_id' => '来源编号', 'original_name' => '原名称',
                'original_account_id' => '原档案编号', 'original_owner_id' => '原负责人']); ?>
        </section>
    <?php endif; ?>
    <?php if ($view === 'leads'): ?>
        <section class="panel table-panel"><h2>联系信息</h2>
            <?php $rowsTable([$result], ['email' => '邮箱', 'phone' => '电话', 'account_id' => '关联客户']); ?>
        </section>
        <section class="panel table-panel"><h2>活动触点</h2>
            <p>首次 <?= e($result['first_touch']['occurred_at'] ?? '—') ?> · 最近 <?= e($result['recent_touch']['occurred_at'] ?? '—') ?>（UTC）</p>
            <?php $rowsTable($result['touches'], ['campaign' => '活动', 'source' => '来源', 'external_id' => '来源编号', 'occurred_at' => '发生时间']); ?>
        </section>
    <?php endif; ?>
    <?php if ($view === 'opportunities'): ?>
        <?php if ($result['quote'] !== null): ?>
            <a class="pager" href="<?= e(url(['company' => $chosen, 'view' => 'quotes', 'id' => $result['quote']['id']])) ?>">查看报价和签约</a>
        <?php endif; ?>
        <section class="panel table-panel"><h2>产品与版本</h2>
            <?php $rowsTable(array_map(static fn (array $line): array => $line + ['unit_price' => Money::format($line['unit_minor'], $result['currency'])],
                $result['products']), ['product_code' => '产品代码', 'product_version' => '版本', 'description' => '名称',
                'quantity' => '数量', 'unit_price' => '单价']); ?>
        </section>
    <?php endif; ?>
    <?php if (in_array($view, ['quotes', 'agreements'], true)): ?>
        <?php require __DIR__ . '/contract-record.php'; ?>
    <?php endif; ?>
    <?php if ($view === 'forecasts'): ?>
        <section class="panel table-panel"><h2>币种汇总</h2>
            <?php $rowsTable($result['totals'], ['currency' => '币种', 'opportunity_count' => '商机数', 'value_minor' => '金额', 'weighted_minor' => '加权额']); ?>
        </section>
        <section class="panel table-panel"><h2>快照明细</h2>
            <?php $rowsTable($result['items'], ['opportunity_id' => '商机编号', 'title' => '商机', 'stage' => '阶段', 'value_minor' => '金额',
                'probability_bps' => '胜率', 'weighted_minor' => '加权额']); ?>
        </section>
    <?php endif; ?>
    <?php if (isset($result['timeline'])): ?>
        <section class="panel table-panel"><h2>操作时间线</h2>
            <?php $rowsTable($result['timeline'], ['occurred_at' => '时间（UTC）', 'actor_name' => '操作者', 'action' => '操作', 'version' => '业务版本']); ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
