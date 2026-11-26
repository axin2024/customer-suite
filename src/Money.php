<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Money
{
    public const MAX_MINOR = 9_000_000_000_000;
    private const CURRENCIES = ['CNY', 'USD', 'EUR', 'GBP', 'JPY', 'HKD', 'SGD', 'AUD', 'CAD', 'CHF', 'KRW'];
    private const ZERO_DECIMAL = ['JPY', 'KRW'];

    public static function currency(mixed $value): string
    {
        return Input::choice(strtoupper(Input::text($value, '币种', 3)), '币种', self::CURRENCIES);
    }

    public static function minor(mixed $value, string $label, string $currency): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', trim($value), $parts)) {
            throw new BusinessError($label . '须为非负十进制金额，最多两位小数。');
        }
        $zeroDecimal = in_array($currency, self::ZERO_DECIMAL, true);
        if ($zeroDecimal && isset($parts[2])) {
            throw new BusinessError($currency . ' 使用整数金额。');
        }
        $minor = $zeroDecimal ? (int) $parts[1] : (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0', STR_PAD_RIGHT);
        if ($minor > self::MAX_MINOR) {
            throw new BusinessError($label . '超过单笔金额上限。');
        }
        return $minor;
    }

    public static function weighted(int $minor, int $basisPoints): int
    {
        return intdiv($minor * $basisPoints + 5000, 10000);
    }

    public static function format(int $minor, string $currency): string
    {
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return (string) $minor;
        }
        $absolute = abs($minor);
        return ($minor < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
