<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class Input
{
    public static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new BusinessError($label . '必须是文字。');
        }
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $max || preg_match('/\p{Cc}/u', $value)
        ) {
            throw new BusinessError($label . '不能为空、含控制字符或超过 ' . $max . ' 个字符。');
        }
        return $value;
    }

    public static function optionalText(mixed $value, string $label, int $max): ?string
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : self::text($value, $label, $max);
    }

    public static function email(mixed $value): ?string
    {
        $email = self::optionalText($value, '邮箱', 254);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new BusinessError('邮箱格式无效。');
        }
        return $email === null ? null : strtolower($email);
    }

    public static function phone(mixed $value): ?string
    {
        $phone = self::optionalText($value, '电话', 60);
        if ($phone === null) {
            return null;
        }
        $phone = preg_replace('/[() .-]/', '', $phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        if (!preg_match('/^\+?[0-9]{7,15}$/D', $phone)) {
            throw new BusinessError('电话应为 7 至 15 位号码，可带国际区号。');
        }
        return $phone;
    }

    public static function id(mixed $value, string $label = '记录编号'): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw new BusinessError($label . '无效。');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new BusinessError($label . '无效。');
        }
        return $id;
    }

    public static function integer(mixed $value, string $label, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new BusinessError($label . '须为 ' . $min . ' 至 ' . $max . ' 的整数。');
        }
        return $value;
    }

    public static function key(mixed $value, string $label, int $max): string
    {
        $value = self::text($value, $label, $max);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]*$/D', $value)) {
            throw new BusinessError($label . '格式无效。');
        }
        return $value;
    }

    public static function choice(mixed $value, string $label, array $options): string
    {
        if (!is_string($value) || !in_array($value, $options, true)) {
            throw new BusinessError($label . '无效。');
        }
        return $value;
    }

    public static function date(mixed $value): string
    {
        $date = self::text($value, '日期', 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) substr($date, 0, 4) < 1000) {
            throw new BusinessError('日期须按 YYYY-MM-DD 输入。');
        }
        return $date;
    }

    public static function instant(mixed $value): string
    {
        $time = self::text($value, '事件时间', 35);
        if (!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](\.\d{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/D', $time, $parts)) {
            throw new BusinessError('事件时间应包含时区，例如 2026-11-24T16:30:00+08:00。');
        }
        self::date($parts[1]);
        return (new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function digest(array $payload): string
    {
        return hash('sha256', self::json(self::canonical($payload)));
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
