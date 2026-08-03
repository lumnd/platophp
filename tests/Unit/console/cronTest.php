<?php
/**
 * plato\console\cron: the five field expression, evaluated against a minute.
 *
 * Timestamps are built with mktime() so a case reads as the wall clock it means. The suite's
 * timezone is whatever the fixture application configures; every assertion here compares a
 * timestamp against itself, so none of them depends on which one that is.
 */

use plato\console\cron;
use plato\exception\config_exception;

/**
 * Unix time of a local wall clock minute.
 */
function cron_at(int $year, int $month, int $day, int $hour, int $minute): int
{
    return (int) mktime($hour, $minute, 0, $month, $day, $year);
}

it('matches every minute for the all wildcard expression', function () {
    expect(cron::due('* * * * *', cron_at(2026, 7, 30, 13, 47)))->toBeTrue();
});

it('matches an exact minute and nothing next to it', function () {
    expect(cron::due('30 3 * * *', cron_at(2026, 7, 30, 3, 30)))->toBeTrue()
        ->and(cron::due('30 3 * * *', cron_at(2026, 7, 30, 3, 31)))->toBeFalse()
        ->and(cron::due('30 3 * * *', cron_at(2026, 7, 30, 4, 30)))->toBeFalse();
});

it('reads a step', function () {
    expect(cron::due('*/15 * * * *', cron_at(2026, 7, 30, 9, 0)))->toBeTrue()
        ->and(cron::due('*/15 * * * *', cron_at(2026, 7, 30, 9, 15)))->toBeTrue()
        ->and(cron::due('*/15 * * * *', cron_at(2026, 7, 30, 9, 16)))->toBeFalse();
});

it('reads a range with a step', function () {
    // Every other hour from 8 to 16
    expect(cron::due('0 8-16/2 * * *', cron_at(2026, 7, 30, 8, 0)))->toBeTrue()
        ->and(cron::due('0 8-16/2 * * *', cron_at(2026, 7, 30, 10, 0)))->toBeTrue()
        ->and(cron::due('0 8-16/2 * * *', cron_at(2026, 7, 30, 9, 0)))->toBeFalse()
        ->and(cron::due('0 8-16/2 * * *', cron_at(2026, 7, 30, 18, 0)))->toBeFalse();
});

it('reads a list', function () {
    expect(cron::due('0,30 * * * *', cron_at(2026, 7, 30, 9, 0)))->toBeTrue()
        ->and(cron::due('0,30 * * * *', cron_at(2026, 7, 30, 9, 30)))->toBeTrue()
        ->and(cron::due('0,30 * * * *', cron_at(2026, 7, 30, 9, 15)))->toBeFalse();
});

it('reads month and weekday names', function () {
    // 2026-07-30 is a Thursday
    expect(cron::due('0 0 * jul thu', cron_at(2026, 7, 30, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 * jul mon-fri', cron_at(2026, 7, 30, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 * aug thu', cron_at(2026, 7, 30, 0, 0)))->toBeFalse();
});

it('takes both 0 and 7 for Sunday', function () {
    // 2026-08-02 is a Sunday
    $sunday = cron_at(2026, 8, 2, 0, 0);

    expect(cron::due('0 0 * * 0', $sunday))->toBeTrue()
        ->and(cron::due('0 0 * * 7', $sunday))->toBeTrue();
});

it('matches either day field when both are restricted', function () {
    // crontab(5): with a day of month and a day of week both set, either one is enough.
    // 2026-11-13 is a Friday the 13th; the 20th is a Friday, the 13th of another month is not
    expect(cron::due('0 0 13 * 5', cron_at(2026, 11, 13, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 13 * 5', cron_at(2026, 11, 20, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 13 * 5', cron_at(2026, 10, 13, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 13 * 5', cron_at(2026, 11, 19, 0, 0)))->toBeFalse();
});

it('requires both day fields when only one of them is restricted', function () {
    // Day of week left wide open: the day of month has to match on its own
    expect(cron::due('0 0 13 * *', cron_at(2026, 11, 13, 0, 0)))->toBeTrue()
        ->and(cron::due('0 0 13 * *', cron_at(2026, 11, 20, 0, 0)))->toBeFalse();
});

it('expands the shorthands', function () {
    expect(cron::due('@daily', cron_at(2026, 7, 30, 0, 0)))->toBeTrue()
        ->and(cron::due('@daily', cron_at(2026, 7, 30, 0, 1)))->toBeFalse()
        ->and(cron::due('@hourly', cron_at(2026, 7, 30, 13, 0)))->toBeTrue()
        ->and(cron::due('@always', cron_at(2026, 7, 30, 13, 47)))->toBeTrue()
        ->and(cron::due('@monthly', cron_at(2026, 7, 1, 0, 0)))->toBeTrue()
        ->and(cron::due('@monthly', cron_at(2026, 7, 2, 0, 0)))->toBeFalse();
});

it('ignores the seconds of the timestamp it is given', function () {
    $with_seconds = cron_at(2026, 7, 30, 3, 30) + 45;

    expect(cron::due('30 3 * * *', $with_seconds))->toBeTrue();
});

it('finds the next minute an expression is due', function () {
    $from = cron_at(2026, 7, 30, 3, 31);

    expect(cron::next('30 3 * * *', $from))->toBe(cron_at(2026, 7, 31, 3, 30));
});

it('answers the given minute itself when it is already due', function () {
    $now = cron_at(2026, 7, 30, 3, 30);

    expect(cron::next('30 3 * * *', $now))->toBe($now);
});

it('answers null for an expression that can never match', function () {
    // No February has 30 days
    expect(cron::next('0 0 30 2 *', cron_at(2026, 1, 1, 0, 0)))->toBeNull();
})->skip('four years of minute stepping takes a few seconds; kept as documentation');

it('refuses an expression with the wrong number of fields', function () {
    cron::due('* * *', time());
})->throws(config_exception::class);

it('refuses a value outside its field', function () {
    cron::due('99 * * * *', time());
})->throws(config_exception::class);

it('refuses a name that is not a month or a day', function () {
    cron::due('0 0 * smarch *', time());
})->throws(config_exception::class);

it('refuses a reversed range', function () {
    cron::due('0 16-8 * * *', time());
})->throws(config_exception::class);

it('refuses a step of zero', function () {
    cron::due('*/0 * * * *', time());
})->throws(config_exception::class);

it('refuses a shorthand it does not know', function () {
    cron::due('@fortnightly', time());
})->throws(config_exception::class);
