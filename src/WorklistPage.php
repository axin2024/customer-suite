<?php

declare(strict_types=1);

namespace CustomerSuite;

final class WorklistPage
{
    public const SIZE = 50;

    public function __construct(public readonly ?string $updatedAt, public readonly ?int $id)
    {
    }

    public static function read(?string $cursor): self
    {
        if ($cursor === null || $cursor === '') {
            return new self(null, null);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^([\d-]{10} [\d:]{8}\.\d{6})\|(\d{1,19})$/D', $decoded, $pieces)) {
            throw new BusinessError('分页标记无效。');
        }
        $updatedAt = Input::instant(str_replace(' ', 'T', $pieces[1]) . 'Z');
        return new self($updatedAt, Input::id($pieces[2]));
    }

    public function clause(): string
    {
        return $this->id === null ? '' : ' AND (updated_at < ? OR (updated_at = ? AND id < ?))';
    }

    public function parameters(): array
    {
        return $this->id === null ? [] : [$this->updatedAt, $this->updatedAt, $this->id];
    }

    public static function result(array $rows): array
    {
        $next = null;
        if (count($rows) > self::SIZE) {
            array_pop($rows);
            $last = $rows[array_key_last($rows)];
            $next = rtrim(strtr(base64_encode($last['updated_at'] . '|' . $last['id']), '+/', '-_'), '=');
        }
        return ['items' => $rows, 'next_cursor' => $next];
    }
}
