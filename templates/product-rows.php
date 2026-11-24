<?php

declare(strict_types=1);

$productFields = [
    'product_code' => '产品代码', 'product_version' => '产品版本', 'description' => '产品名称',
    'quantity' => '数量', 'unit_price' => '单价',
];
$renderRow = static function (array $item, string $number) use ($productFields): void {
    echo '<div class="product-row" data-product-row>';
    foreach ($productFields as $key => $label) {
        echo '<label>', e($label), '<input data-product-field="', e($key),
            '" name="data[products][', e($number), '][', e($key), ']" value="', e($item[$key] ?? ''), '" required',
            $key === 'quantity' ? ' type="number" min="1" max="1000000"' : '',
            $key === 'unit_price' ? ' inputmode="decimal"' : '', '></label>';
    }
    echo '<button type="button" data-remove-product>删除行</button></div>';
};
?>
<div class="full" data-products>
    <input type="hidden" name="data[products]" value="[]">
    <div data-product-rows>
        <?php foreach ($items as $i => $item): ?><?php $renderRow($item, (string) $i); ?><?php endforeach; ?>
    </div>
    <template data-product-template><?php $renderRow(['quantity' => 1], '0'); ?></template>
    <button type="button" data-add-product>添加产品</button>
</div>
