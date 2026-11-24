<?php

declare(strict_types=1);

namespace CustomerSuite;

final class OpportunityLines
{
    public static function parse(mixed $input, string $currency): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 100) {
            throw new BusinessError('产品明细须为最多 100 行的数组。');
        }
        $items = [];
        $total = 0;
        foreach ($input as $index => $line) {
            if (!is_array($line) || array_is_list($line)) {
                throw new BusinessError('产品明细行须为对象。');
            }
            $qty = Input::id($line['quantity'] ?? null, '产品数量');
            $price = Money::minor($line['unit_price'] ?? null, '产品单价', $currency);
            if ($qty > 1_000_000 || ($price > 0 && $qty > intdiv(Money::MAX_MINOR - $total, $price))) {
                throw new BusinessError('产品数量或金额超出上限。');
            }
            $items[] = [$index + 1,
                Input::key($line['product_code'] ?? null, '产品代码', 48),
                Input::key($line['product_version'] ?? null, '产品版本', 48),
                Input::text($line['description'] ?? null, '产品名称', 160), $qty, $price];
            $total += $qty * $price;
        }
        return ['items' => $items, 'total' => $total];
    }
}
