<?php

declare(strict_types=1);

use CustomerSuite\Agreements;
use CustomerSuite\Money;
use CustomerSuite\Workbench;

$idVersion = ['id' => $result['id'], 'version' => $result['version']];
?>
<?php if ($view === 'quotes'): ?>
    <section class="panel table-panel"><h2>报价条款和审批</h2>
        <p><?= e($result['terms']) ?></p>
        <?php $rowsTable([$result], ['gross_minor' => '原价', 'policy_version' => '折扣规则版本', 'decision' => '审批结论', 'reason' => '审批依据']); ?>
    </section>
    <section class="panel table-panel"><h2>报价产品快照</h2>
        <?php $rowsTable(array_map(static fn (array $line): array => $line + ['unit_price' => Money::format($line['unit_minor'], $result['currency'])],
            $result['products']), ['product_code' => '产品代码', 'product_version' => '版本', 'description' => '名称', 'quantity' => '数量', 'unit_price' => '单价']); ?>
    </section>
    <?php if ($result['allocations']): ?>
        <section class="panel table-panel"><h2>签约合同</h2>
            <ul>
                <?php foreach ($result['allocations'] as $item): ?>
                    <li><a href="<?= e(url(['company' => $item['company_id'], 'view' => 'agreements', 'id' => $item['id']])) ?>">
                        <?= e($item['company_name'] . ' · ' . $item['reference_no'] . ' · ' . $item['legal_name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
    <?php if ($result['can_prepare'] && $result['state'] !== 'archived'): ?>
        <?php if ($result['state'] === 'draft'): ?>
            <?php formPanel('提交折扣审批', $csrf, 'quote.submit', '提交报价', static function (): void {}, $idVersion); ?>
        <?php endif; ?>
        <?php if ($result['state'] !== 'contracted' && ($result['state'] !== 'pending' || $result['expires_on'] < gmdate('Y-m-d'))): ?>
            <?php formPanel('新建报价修订', $csrf, 'quote.prepare', '保存新修订', static function () use ($result): void {
                field('discount_bps', '折扣（基点，100 为 1%）', 'text', true, $result['discount_bps']);
                field('expires_on', '报价有效至（UTC）', 'date');
                field('terms', '报价条款', 'text', true, $result['terms']);
            }, ['opportunity_id' => $result['opportunity_id'], 'opportunity_version' => $result['opportunity_version'], 'version' => $result['version']]); ?>
        <?php endif; ?>
        <?php if ($result['state'] === 'approved' && $result['expires_on'] >= gmdate('Y-m-d')): ?>
            <?php formPanel('登记本公司签约', $csrf, 'agreement.sign', '登记合同', static function () use ($result, $chosen): void {
                $items = [['company_id' => $chosen, 'account_id' => $result['account_id'], 'amount' => Money::format($result['net_minor'], $result['currency'])]];
                $group = false;
                require __DIR__ . '/signing-rows.php';
            }, $idVersion + ['scope' => 'single']); ?>
            <?php if ($manager): ?>
                <?php formPanel('登记集团分签', $csrf, 'agreement.sign', '登记分签合同', static function () use ($result): void {
                    $items = [[], [], []];
                    $group = true;
                    require __DIR__ . '/signing-rows.php';
                }, $idVersion + ['scope' => 'group']); ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($result['state'] === 'pending' && $result['can_review']): ?>
        <?php formPanel('复核折扣', $csrf, 'quote.decide', '保存审批结论', static function (): void {
            selectField('decision', '审批结论', ['approved' => '批准', 'rejected' => '拒绝']);
            field('reason', '审批依据');
        }, $idVersion); ?>
    <?php endif; ?>
<?php else: ?>
    <section class="panel table-panel"><h2>合同第 <?= e($result['revision']) ?> 版</h2>
        <p><?= e($result['terms']) ?></p>
        <?php $rowsTable([$result], ['legal_registration_ref' => '登记号', 'decision' => '本版状态', 'starts_on' => '生效日期',
            'based_on' => '依据版本', 'evidence_ref' => '签署凭据', 'change_reason' => '变更原因', 'decision_reason' => '复核依据']); ?>
    </section>
    <?php if ($result['can_change'] && $result['latest_decision'] !== 'pending' && $result['state'] !== 'ended'): ?>
        <?php formPanel('新增补充协议', $csrf, 'agreement.propose', '提交复核', static function () use ($result): void {
            field('amount', '新合同金额（' . $result['currency'] . '）', 'text', true, Money::format($result['value_minor'], $result['currency']));
            field('starts_on', '新生效日期', 'date', true, $result['starts_on']);
            field('ends_on', '新到期日期', 'date', true, $result['ends_on']);
            selectField('service_level', '新服务级别', array_replace([$result['service_level'] => Agreements::LEVELS[$result['service_level']]], Agreements::LEVELS));
            field('terms', '新合同条款', 'text', true, $result['terms']);
            field('reason', '变更依据');
        }, $idVersion); ?>
        <?php formPanel('变更合同状态', $csrf, 'agreement.move', '保存状态', static function () use ($result): void {
            selectField('state', '目标状态', array_intersect_key(Workbench::STATES, array_flip(Agreements::PROGRESSION[$result['state']])));
            field('reason', '变更依据');
        }, $idVersion); ?>
    <?php endif; ?>
    <?php if ($result['can_review']): ?>
        <?php formPanel('复核补充协议', $csrf, 'agreement.decide', '保存复核结果', static function (): void {
            selectField('decision', '处理结果', ['signed' => '登记签署', 'rejected' => '拒绝']);
            field('evidence_ref', '签署凭据编号（登记签署时必填）', 'text', false);
            field('reason', '复核依据');
        }, $idVersion); ?>
    <?php endif; ?>
<?php endif; ?>
<?php if ($result['revisions']): ?>
    <section class="panel table-panel"><h2>版本记录</h2>
        <ul>
            <?php foreach ($result['revisions'] as $item): ?>
                <li><a href="<?= e(url($baseLink + ['id' => $result['id'], 'revision' => $item['revision']])) ?>"
                       <?= $item['revision'] === $result['revision'] ? 'aria-current="page"' : '' ?>>
                    第 <?= e($item['revision']) ?> 版 · <?= e($item['currency']) ?> <?= e(Money::format($item['net_minor'] ?? $item['value_minor'], $item['currency'])) ?>
                    · <?= e($stateNames[$item['decision'] ?? ''] ?? $item['expires_on'] ?? '') ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
