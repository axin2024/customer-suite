<?php

declare(strict_types=1);

use CustomerSuite\BusinessCalendar;
use CustomerSuite\Input;

require __DIR__ . '/bootstrap.php';

$office = [['09:00', '12:00'], ['13:00', '18:00']];
$week = array_fill_keys([1, 2, 3, 4, 5], $office);
$newCalendar = static function (string $zone, array $week, array $exceptions = []): BusinessCalendar {
    $record = BusinessCalendar::validate($zone, $week, $exceptions);
    return BusinessCalendar::fromRecord(['timezone' => $record['timezone'], 'weekly_hours' => Input::json($record['weekly']),
        'exception_hours' => Input::json($record['exceptions'])]);
};
$calendar = $newCalendar('Asia/Shanghai', $week);
$friday = '2026-11-20 09:50:00.000000';
$monday = '2026-11-23 01:20:00.000000';
same($monday, $calendar->deadline($friday, 30 * BusinessCalendar::MINUTE), 'Friday 17:50 CN, thirty minutes due Monday 09:20.');
same(30 * BusinessCalendar::MINUTE, $calendar->elapsed($friday, $monday), 'Weekend adds no elapsed business time.');
same(10 * BusinessCalendar::MINUTE, $calendar->elapsed($friday, '2026-11-22 12:00:00.000000'), 'Friday portion is retained.');
same('2026-11-23 05:10:00.000000', $calendar->deadline('2026-11-23 03:50:00.000000', 20 * BusinessCalendar::MINUTE), 'Lunch interval pauses the calendar.');
$holiday = $newCalendar('Asia/Shanghai', $week, ['2026-11-23' => []]);
same('2026-11-24 01:20:00.000000', $holiday->deadline($friday, 30 * BusinessCalendar::MINUTE), 'Holiday shifts Monday workload to Tuesday.');
$makeupDay = $newCalendar('Asia/Shanghai', $week, ['2026-11-21' => [['10:00', '11:30']]]);
same('2026-11-21 02:20:00.000000', $makeupDay->deadline($friday, 30 * BusinessCalendar::MINUTE), 'Makeup Saturday is included.');
same('2026-11-23 01:00:00.500000', $calendar->deadline('2026-11-20 09:59:00.500000', BusinessCalendar::MINUTE),
    'Fractional seconds survive the weekend boundary.');
$newYork = $newCalendar('America/New_York', [7 => [['01:00', '04:00']]]);
same(120 * BusinessCalendar::MINUTE, $newYork->elapsed('2026-03-08 06:00:00.000000', '2026-03-08 08:00:00.000000'),
    'Spring clock change has two actual hours inside 01:00–04:00.');
same(240 * BusinessCalendar::MINUTE, $newYork->elapsed('2026-11-01 05:00:00.000000', '2026-11-01 09:00:00.000000'),
    'Fall clock change includes the repeated local hour.');
same('2026-11-01 06:30:00.000000', $newYork->deadline('2026-11-01 05:30:00.000000', 60 * BusinessCalendar::MINUTE),
    'Deadline falls in the second occurrence of the repeated hour.');
rejects(422, fn () => $newCalendar('Asia/Shanghai', [1 => [['08:00', '14:00'], ['13:00', '18:00']]]), 'Reject overlapping ranges.');
rejects(422, fn () => $newCalendar('CN', $week), 'Reject non-IANA time zone aliases.');
rejects(422, fn () => $newCalendar('UTC', [1 => [['00:00', '00:01']]]), 'Calendar must have enough regular coverage.');
done('Business calendar, weekends, holidays and offset changes');
