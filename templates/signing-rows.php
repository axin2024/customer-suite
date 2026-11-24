<?php

declare(strict_types=1);

use CustomerSuite\Agreements;

$signingRow = static function (array $values, int $index) use ($group, $result): void {
    $prefix = 'data[allocations][' . $index . ']';
    ?>
    <fieldset class="allocation-row" data-repeat-row>
        <legend>签约主体</legend>
        <?php if ($group): ?>
            <label>签约公司编号<input type="number" min="1" step="1" name="<?= e($prefix) ?>[company_id]" data-repeat-field="company_id" required></label>
            <label>签约客户编号<input type="number" min="1" step="1" name="<?= e($prefix) ?>[account_id]" data-repeat-field="account_id" required></label>
        <?php else: ?>
            <input type="hidden" name="<?= e($prefix) ?>[company_id]" data-repeat-field="company_id" value="<?= e($values['company_id']) ?>">
            <input type="hidden" name="<?= e($prefix) ?>[account_id]" data-repeat-field="account_id" value="<?= e($values['account_id']) ?>">
        <?php endif; ?>
        <label>合同编号<input name="<?= e($prefix) ?>[reference_no]" data-repeat-field="reference_no" required></label>
        <label>签约金额（<?= e($result['currency']) ?>）<input name="<?= e($prefix) ?>[amount]" data-repeat-field="amount" value="<?= e($values['amount'] ?? '') ?>" required></label>
        <label>生效日期<input type="date" name="<?= e($prefix) ?>[starts_on]" data-repeat-field="starts_on" required></label>
        <label>到期日期<input type="date" name="<?= e($prefix) ?>[ends_on]" data-repeat-field="ends_on" required></label>
        <label>服务级别<select name="<?= e($prefix) ?>[service_level]" data-repeat-field="service_level">
                <?php foreach (Agreements::LEVELS as $value => $title): ?><option value="<?= e($value) ?>"><?= e($title) ?></option><?php endforeach; ?>
        </select></label>
        <label>签署凭据编号<input name="<?= e($prefix) ?>[evidence_ref]" data-repeat-field="evidence_ref" required></label>
        <?php if ($group): ?><button type="button" data-repeat-remove>移除该主体</button><?php endif; ?>
    </fieldset>
    <?php
};
?>
<div class="form-wide" data-repeat="allocations" data-repeat-max="20" data-repeat-min="1">
    <div data-repeat-rows>
        <?php foreach ($items as $index => $values) { $signingRow($values, $index); } ?>
    </div>
    <?php if ($group): ?>
        <template data-repeat-template><?php $signingRow([], 0); ?></template>
        <button type="button" data-repeat-add>增加签约主体</button>
    <?php endif; ?>
</div>
