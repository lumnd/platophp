<?php
/**
 * plato\date: timezone conversion, durations, calendar ranges and format validation.
 *
 * Every case names its own timezone rather than leaning on the configured one, so the file says
 * the same thing whatever the container's date.timezone is. The two cases that do exercise the
 * configuration put it back in afterEach().
 */

use plato\date;
use plato\plato;

plato::registry(plato_test_config());

afterEach(function () {
    date::reset();
});

it('reads a timestamp as an instant and a string as a wall clock reading', function () {
    // 2026-07-31 00:00:00 UTC
    $moment = date::make(1785456000, 'Asia/Shanghai');
    expect($moment->format('Y-m-d H:i:s'))->toBe('2026-07-31 08:00:00');
    expect($moment->getTimestamp())->toBe(1785456000);

    // The same reading in two zones is two different instants
    $shanghai = date::make('2026-07-31 08:00:00', 'Asia/Shanghai');
    $utc      = date::make('2026-07-31 08:00:00', 'UTC');
    expect($shanghai->getTimestamp())->toBe(1785456000);
    expect($utc->getTimestamp() - $shanghai->getTimestamp())->toBe(28800);
});

it('accepts a DateTimeInterface without losing the instant', function () {
    $source = new DateTime('2026-07-31 08:00:00', new DateTimeZone('Asia/Shanghai'));
    $moment = date::make($source, 'UTC');

    expect($moment->getTimestamp())->toBe($source->getTimestamp());
    expect($moment->format('Y-m-d H:i:s'))->toBe('2026-07-31 00:00:00');
});

it('reads numeric strings as timestamps', function () {
    expect(date::timestamp('1785456000'))->toBe(1785456000);
});

it('converts between zones', function () {
    expect(date::convert(1785456000, 'Asia/Shanghai', 'Y-m-d H:i'))->toBe('2026-07-31 08:00');
    expect(date::convert(1785456000, 'UTC', 'Y-m-d H:i'))->toBe('2026-07-31 00:00');
    expect(date::convert('2026-07-31 08:00:00', 'UTC', 'Y-m-d H:i', 'Asia/Shanghai'))
        ->toBe('2026-07-31 00:00');
});

it('falls back to the configured zone and format', function () {
    date::configure(['timezone' => 'Asia/Shanghai', 'format' => 'Y-m-d H:i']);

    expect(date::format(1785456000))->toBe('2026-07-31 08:00');
    expect(date::zone()->getName())->toBe('Asia/Shanghai');
});

it('merges an override rather than replacing the settings', function () {
    date::configure(['timezone' => 'UTC']);

    expect(date::config('timezone'))->toBe('UTC');
    expect(date::config('format'))->toBe('Y-m-d H:i:s');
});

it('falls back to the process zone when nothing is configured', function () {
    expect(date::zone()->getName())->toBe(date_default_timezone_get());
});

it('applies relative modifications', function () {
    expect(date::modify('2026-01-31', '+1 month', 'Y-m-d'))->toBe('2026-03-03');
    expect(date::modify('2026-01-31', 'last day of next month', 'Y-m-d'))->toBe('2026-02-28');
    expect(date::modify(1785456000, '-1 day', 'Y-m-d', 'UTC'))->toBe('2026-07-30');
});

it('rejects a modification php does not understand', function () {
    expect(fn () => date::modify('2026-01-31', 'not a relative format'))
        ->toThrow(InvalidArgumentException::class);
});

it('splits seconds into parts', function () {
    expect(date::parts(90061))->toBe([
        'negative' => false,
        'days'     => 1,
        'hours'    => 1,
        'minutes'  => 1,
        'seconds'  => 1,
    ]);

    expect(date::parts(-30))->toMatchArray(['negative' => true, 'seconds' => 30]);
});

it('renders durations without leading zero units', function () {
    expect(date::duration(90061))->toBe('1d 1h 1m 1s');
    expect(date::duration(90))->toBe('1m 30s');
    expect(date::duration(0))->toBe('0s');
    expect(date::duration(-90))->toBe('-1m 30s');
});

it('takes the caller wording for durations', function () {
    $units = ['days' => '天', 'hours' => '小时', 'minutes' => '分', 'seconds' => '秒'];

    expect(date::duration(90061, $units, ''))->toBe('1天1小时1分1秒');
    // A partial list still fills in from the defaults rather than printing an empty suffix
    expect(date::duration(90, ['minutes' => ' min '], '|'))->toBe('1 min |30s');
});

it('counts date boundaries rather than 86400 second blocks', function () {
    expect(date::diff_days('2026-07-30 23:00:00', '2026-07-31 01:00:00'))->toBe(1);
    expect(date::diff_days('2026-07-31 01:00:00', '2026-07-31 23:00:00'))->toBe(0);
    // Always positive, whichever way round it is asked
    expect(date::diff_days('2026-08-10', '2026-07-31'))->toBe(10);
    expect(date::diff_days('2026-07-31', '2026-08-10'))->toBe(10);
});

it('answers iso week ranges', function () {
    expect(date::week_range(31, 2026))->toBe(['start' => '2026-07-27', 'end' => '2026-08-02']);
    // Week 1 is the one holding the first Thursday, so it can start in the previous year
    expect(date::week_range(1, 2026))->toBe(['start' => '2025-12-29', 'end' => '2026-01-04']);
});

it('answers month ranges, february included', function () {
    expect(date::month_range('2026-07-15'))->toBe(['start' => '2026-07-01', 'end' => '2026-07-31']);
    expect(date::month_range('2026-02-15'))->toBe(['start' => '2026-02-01', 'end' => '2026-02-28']);
    expect(date::month_range('2024-02-15'))->toBe(['start' => '2024-02-01', 'end' => '2024-02-29']);
});

it('answers the offset between two zones', function () {
    expect(date::offset('Asia/Shanghai', 'UTC'))->toBe(28800);
    expect(date::offset('UTC', 'Asia/Shanghai'))->toBe(-28800);
    expect(date::offset('UTC', 'UTC'))->toBe(0);
});

it('validates a date by round trip, not by strtotime', function () {
    expect(date::valid('2026-07-31'))->toBeTrue();
    expect(date::valid('2026-07-31 08:00:00'))->toBeTrue();
    // strtotime() accepts this one and answers March 3rd
    expect(date::valid('2026-02-31'))->toBeFalse();
    expect(date::valid('2026-7-31'))->toBeFalse();
    expect(date::valid('not a date'))->toBeFalse();
    expect(date::valid('31/07/2026', 'd/m/Y'))->toBeTrue();
});

it('answers milliseconds', function () {
    $before = date::millis();
    usleep(2000);
    $after = date::millis();

    expect($after)->toBeGreaterThan($before);
    expect($before)->toBeGreaterThan(1_600_000_000_000);
});
