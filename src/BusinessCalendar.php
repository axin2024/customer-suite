<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class BusinessCalendar
{
    public const MINUTE = 60_000_000;
    private const SECOND = 1_000_000;
    private const DAY_SECONDS = 86_400;
    private readonly DateTimeZone $zone;

    private function __construct(string $timezone, private readonly array $weekly, private readonly array $exceptions)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public static function validate(string $timezone, array $week, array $exceptions): array
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true) || count($week) > 7 || count($exceptions) > 366) {
            throw new BusinessError('工作日历须指定 IANA 时区、7 天以内的周配置及最多 366 项特别日期。');
        }
        $weekly = [];
        $weeklyMinutes = 0;
        foreach ($week as $day => $ranges) {
            $day = Input::integer($day, '周内工作日', 1, 7);
            $weekly[$day] = self::ranges($ranges);
            foreach ($weekly[$day] as [$start, $end]) {
                $weeklyMinutes += $end - $start;
            }
        }
        if ($weeklyMinutes < 60) {
            throw new BusinessError('工作日历每周至少须安排 60 分钟。');
        }
        $special = [];
        foreach ($exceptions as $date => $ranges) {
            $special[Input::date($date)] = self::ranges($ranges);
        }
        return ['timezone' => $timezone, 'weekly' => $weekly, 'exceptions' => $special];
    }

    public static function fromRecord(array $record): self
    {
        return new self($record['timezone'], json_decode($record['weekly_hours'], true, 16, JSON_THROW_ON_ERROR),
            json_decode($record['exception_hours'], true, 16, JSON_THROW_ON_ERROR));
    }

    public function elapsed(string $from, string $to): int
    {
        $start = self::epoch($from);
        $end = self::epoch($to);
        if ($end <= $start) {
            return 0;
        }
        $total = 0;
        $lastDay = $this->localDay($end);
        for ($day = $this->localDay($start); $day <= $lastDay; $day = $day->modify('+1 day')) {
            foreach ($this->intervals($day) as [$opens, $closes]) {
                $total += max(0, min($end, $closes) - max($start, $opens));
            }
        }
        return $total;
    }

    public function deadline(string $from, int $remaining): string
    {
        if ($remaining <= 0) {
            return $from;
        }
        $start = self::epoch($from);
        for ($day = $this->localDay($start); (int) $day->format('Y') <= 9999; $day = $day->modify('+1 day')) {
            foreach ($this->intervals($day) as [$opens, $closes]) {
                $opens = max($opens, $start);
                $duration = $closes - $opens;
                if ($duration <= 0) {
                    continue;
                }
                if ($remaining <= $duration) {
                    return self::timestamp($opens + $remaining);
                }
                $remaining -= $duration;
            }
        }
        throw new BusinessError('当前日历计算的服务时限超出可用日期。');
    }

    public static function minutes(int $micros): float
    {
        return round($micros / self::MINUTE, 4);
    }

    private function localDay(int $instant): DateTimeImmutable
    {
        $seconds = self::splitEpoch($instant)[0];
        $local = (new DateTimeImmutable('@' . $seconds))->setTimezone($this->zone);
        return new DateTimeImmutable($local->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    private function intervals(DateTimeImmutable $wallDate): array
    {
        $schedule = $this->exceptions[$wallDate->format('Y-m-d')] ?? $this->weekly[(int) $wallDate->format('N')] ?? [];
        if ($schedule === []) {
            return [];
        }
        $wallMidnight = $wallDate->getTimestamp();
        $stop = $wallMidnight + 2 * self::DAY_SECONDS;
        $transitions = $this->zone->getTransitions($wallMidnight - 2 * self::DAY_SECONDS, $stop);
        $intervals = [];
        // Intersect each local interval with real offset segments. Repeated hours remain usable,
        // and a gap from a clock change adds no imaginary working time.
        foreach ($transitions as $index => $transition) {
            $endsAt = $transitions[$index + 1]['ts'] ?? $stop;
            foreach ($schedule as [$start, $end]) {
                $opens = max($transition['ts'], $wallMidnight + 60 * $start - $transition['offset']);
                $closes = min($endsAt, $wallMidnight + 60 * $end - $transition['offset']);
                if ($opens < $closes) {
                    $intervals[] = [$opens * self::SECOND, $closes * self::SECOND];
                }
            }
        }
        return $intervals;
    }

    private static function ranges(mixed $ranges): array
    {
        if (!is_array($ranges) || !array_is_list($ranges) || count($ranges) > 8) {
            throw new BusinessError('每天的时间段须为最多八组开始和结束时间。');
        }
        $result = [];
        $previous = -1;
        foreach ($ranges as $range) {
            if (!is_array($range) || !array_is_list($range) || count($range) !== 2) {
                throw new BusinessError('每个时间段须包含开始和结束时间。');
            }
            $start = self::wallMinute($range[0]);
            $end = self::wallMinute($range[1]);
            if ($start < $previous || $start >= $end) {
                throw new BusinessError('工作时段须按先后排列，不得重叠或跨天。');
            }
            $result[] = [$start, $end];
            $previous = $end;
        }
        return $result;
    }

    private static function wallMinute(mixed $value): int
    {
        if ($value === '24:00') {
            return 1440;
        }
        if (!is_string($value) || !preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $value, $parts)) {
            throw new BusinessError('工作时段须写为 HH:MM，结束时间可为 24:00。');
        }
        return (int) $parts[1] * 60 + (int) $parts[2];
    }

    private static function epoch(string $time): int
    {
        $time = new DateTimeImmutable($time, new DateTimeZone('UTC'));
        return $time->getTimestamp() * self::SECOND + (int) $time->format('u');
    }

    private static function timestamp(int $micros): string
    {
        [$seconds, $fraction] = self::splitEpoch($micros);
        $date = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
        return $date->format('Y-m-d H:i:s') . sprintf('.%06d', $fraction);
    }

    private static function splitEpoch(int $micros): array
    {
        $seconds = intdiv($micros, self::SECOND);
        $fraction = $micros % self::SECOND;
        return $fraction < 0 ? [$seconds - 1, $fraction + self::SECOND] : [$seconds, $fraction];
    }
}
