<?php

declare(strict_types=1);

use CustomerSuite\Reports;
use CustomerSuite\Workbench;

$base = ['company' => $chosen, 'view' => 'reports'];
$isDetail = isset($query['id']);
$stageLabels = Workbench::STATES;
$cny = static function (int|string|null $minor): string {
    if ($minor === null) {
        return '待确认';
    }
    $digits = str_pad((string) $minor, 3, '0', STR_PAD_LEFT);
    return '¥' . substr($digits, 0, -2) . '.' . substr($digits, -2);
};
?>
<?php if (!$isDetail): ?>
    <?php if (isset($result['fx_sets'])): ?>
        <?php formPanel('登记汇率版本', $csrf, 'report.fx', '保存汇率', static function (): void {
            field('rate_date', '汇率日（UTC）', 'date', true, gmdate('Y-m-d'));
            field('source', '来源或批准凭据');
            textAreaField('rates', '汇率 JSON（每一主单位外币折合人民币，CNY 自动为 1）', '{}', 3);
        }); ?>
        <?php if ($result['fx_sets'] !== []): ?>
            <?php formPanel('创建经营快照', $csrf, 'report.capture', '生成快照', static function () use ($result, $chosen): void {
                $versions = [];
                foreach ($result['fx_sets'] as $version) {
                    $versions[$version['id']] = '#' . $version['id'] . ' · ' . $version['rate_date'] . ' · ' . $version['source'];
                }
                field('label', '报表名称');
                selectField('fx_set_id', '汇率版本', $versions);
                field('as_of', '统计时点（留空为当前，例 2026-11-15T18:00:00+08:00）', required: false);
                field('cohort_start', '纳入起始日（UTC，可留空）', 'date', false);
                echo '<fieldset><legend>纳入公司</legend>';
                foreach ($result['eligible_companies'] as $id => $name) {
                    echo '<label><input type="checkbox" name="data[company_ids][]" value="', e($id), '"',
                        $id === $chosen ? ' checked' : '', '> ', e($name), '</label>';
                }
                echo '</fieldset>';
            }); ?>
        <?php endif; ?>
    <?php endif; ?>
    <section class="panel table-panel">
        <h2>经营快照</h2>
        <?php if ($result['items'] === []): ?><p class="empty">暂无可查看的经营快照</p><?php else: ?>
            <div class="table-scroll"><table><thead><tr><th scope="col">快照</th><th scope="col">统计时点（UTC）</th>
                <th scope="col">纳入起始日</th></tr></thead><tbody>
            <?php foreach ($result['items'] as $row): ?>
                <tr><td><a href="<?= e(url($base + ['id' => $row['id']])) ?>"><?= e($row['label'] ?? '经营快照 #' . $row['id']) ?></a></td>
                    <td><?= e($row['as_of']) ?></td><td><?= e($row['cohort_start'] ?? '全部历史') ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
    <?php if (count($result['items']) === 50): ?>
        <a class="pager" href="<?= e(url($base + ['before' => $result['items'][49]['id']])) ?>">更早的快照</a>
    <?php endif; ?>
<?php else: ?>
    <?php
    $filters = array_intersect_key($query, array_flip(['scope_company', 'kind', 'stage']));
    $detail = $base + ['id' => $result['id']];
    $names = array_column($result['companies'], 'name', 'id');
    $export = ['company' => $chosen, 'report' => $result['id']] + $filters;
    ?>
    <a class="pager" href="<?= e(url($base)) ?>">← 返回经营分析</a>
    <section class="panel table-panel">
        <h2><?= e($result['label'] ?? '经营快照 #' . $result['id']) ?></h2>
        <p>截至 <?= e($result['as_of']) ?> UTC · 汇率版本 #<?= e($result['fx_set_id']) ?> · 汇率日 <?= e($result['rate_date']) ?></p>
        <nav class="filters" aria-label="报表范围">
            <a href="<?= e(url($detail + array_diff_key($filters, ['scope_company' => true]))) ?>">当前授权汇总</a>
            <?php foreach ($names as $id => $name): ?>
                <a href="<?= e(url($detail + ['scope_company' => $id] + $filters)) ?>"
                    <?= (string) ($filters['scope_company'] ?? '') === (string) $id ? 'aria-current="true"' : '' ?>><?= e($name) ?></a>
            <?php endforeach; ?>
            <a href="/export.php?<?= e(http_build_query($export)) ?>">导出当前范围 CSV</a>
        </nav>
        <div class="table-scroll"><table><thead><tr><th scope="col">类型</th><th scope="col">记录数</th>
            <th scope="col">已折算</th><th scope="col">人民币合计</th></tr></thead><tbody>
        <?php foreach ($result['group_totals'] as $row): ?>
            <tr><td><?= e(Reports::KINDS[$row['kind']]) ?></td><td><?= e($row['record_count']) ?></td>
                <td><?= e($row['valued_count']) ?></td><td><?= e($cny($row['cny_minor'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($result['group_totals'] === []): ?><tr><td colspan="4">当前范围暂无可见记录</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel table-panel">
        <h2>公司与阶段分布</h2>
        <div class="table-scroll"><table><thead><tr><th scope="col">公司</th><th scope="col">类型和阶段</th>
            <th scope="col">记录数</th><th scope="col">已折算</th><th scope="col">人民币合计</th></tr></thead><tbody>
        <?php foreach ($result['totals'] as $row): ?>
            <?php $scope = $detail + ['scope_company' => $row['company_id'], 'kind' => $row['kind'], 'stage' => $row['stage']]; ?>
            <tr><td><?= e($names[$row['company_id']]) ?></td><td><a href="<?= e(url($scope)) ?>">
                <?= e($row['stage'] === null ? Reports::KINDS[$row['kind']] : $stageLabels[$row['stage']]) ?></a></td>
                <td><?= e($row['record_count']) ?></td><td><?= e($row['valued_count']) ?></td><td><?= e($cny($row['cny_minor'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php if (isset($filters['kind']) || isset($filters['stage'])): ?>
            <a href="<?= e(url($detail + array_intersect_key($filters, ['scope_company' => true]))) ?>">清除类型与阶段筛选</a>
        <?php endif; ?>
    </section>
    <?php if ($result['funnel'] !== []): ?>
        <section class="panel table-panel"><h2>商机转化漏斗</h2>
            <div class="table-scroll"><table><thead><tr><th scope="col">公司</th><th scope="col">进入</th><th scope="col">已确认资格</th>
                <th scope="col">曾报价</th><th scope="col">曾谈判</th><th scope="col">赢单</th><th scope="col">流失</th>
                <th scope="col">赢单率</th></tr></thead><tbody>
            <?php foreach ($result['funnel'] as $row): ?>
                <tr><td><?= e($names[$row['company_id']]) ?></td>
                    <?php foreach (['entered', 'qualified', 'proposal', 'negotiation', 'won', 'lost'] as $metric): ?>
                        <td><?= e($row[$metric]) ?></td>
                    <?php endforeach; ?>
                    <td><?= e(number_format($row['won'] * 100 / $row['entered'], 1)) ?>%</td></tr>
            <?php endforeach; ?></tbody></table></div>
        </section>
        <section class="panel table-panel"><h2>渠道贡献</h2>
            <?php foreach (['first' => '首次触点', 'recent' => '最近触点'] as $attribution => $label): ?>
                <h3><?= e($label) ?></h3>
                <div class="table-scroll"><table><thead><tr><th scope="col">公司</th><th scope="col">渠道</th>
                    <th scope="col">商机</th><th scope="col">赢单</th><th scope="col">已折算赢单额</th></tr></thead><tbody>
                <?php foreach ($result['channels'][$attribution] as $row): ?>
                    <tr><td><?= e($names[$row['company_id']]) ?></td><td><?= e($row['channel'] ?? '无触点') ?></td>
                        <td><?= e($row['opportunities']) ?></td><td><?= e($row['won']) ?></td><td><?= e($cny($row['won_cny_minor'])) ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <section class="panel table-panel"><h2>业务明细</h2>
        <div class="table-scroll"><table><thead><tr><th scope="col">公司</th><th scope="col">来源</th><th scope="col">版本</th>
            <th scope="col">原币最小单位</th><th scope="col">汇率</th><th scope="col">人民币</th></tr></thead><tbody>
        <?php foreach ($result['items'] as $row): ?>
            <?php $origin = ['company' => $row['company_id'], 'view' => $row['kind'] === 'agreement' ? 'agreements' : 'opportunities',
                'id' => $row['object_id'], 'revision' => $row['kind'] === 'agreement' ? $row['source_version'] : null]; ?>
            <tr><td><?= e($row['company_name']) ?></td><td><a href="<?= e(url($origin)) ?>"><?= e($row['title']) ?></a></td>
                <td>v<?= e($row['source_version']) ?></td><td><?= e($row['value_minor'] ?? '待确认') ?> <?= e($row['currency']) ?></td>
                <td><?= e($row['fx_rate']) ?></td><td><?= e($cny($row['cny_minor'])) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php if ($result['next'] !== null): ?>
            <a class="pager" href="<?= e(url($detail + $filters + $result['next'])) ?>">下一页明细</a>
        <?php endif; ?>
    </section>
<?php endif; ?>
