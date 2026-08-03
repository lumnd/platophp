<?php

/**
 * Cron expression matching: is this expression due at this minute
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\exception\config_exception;

/**
 * The five field cron expression, evaluated against a point in time.
 *
 *     cron::due('*&#47;5 * * * *', time())      // every five minutes
 *     cron::due('30 3 * * 1', time())      // 03:30 on Mondays
 *
 * Fields, in order: minute (0-59), hour (0-23), day of month (1-31), month (1-12), day of week
 * (0-7, where both 0 and 7 are Sunday). Each accepts `*`, a number, a `a-b` range, a `a-b/n` or
 * `*&#47;n` step, and a comma separated list of any of those. Month and weekday names are accepted in
 * their three letter English form, because a crontab that reads `30 3 * * mon` is worth the
 * twenty lines it costs.
 *
 * Shorthands: `@yearly` / `@annually`, `@monthly`, `@weekly`, `@daily` / `@midnight`, `@hourly`,
 * and `@always` for every minute.
 *
 * **Not supported, deliberately:** `@reboot` (nothing here owns a boot), `L`, `W` and `#`
 * (last / nearest weekday / nth weekday of the month -- Quartz extensions that no crontab has),
 * and seconds. A schedule this class cannot express is a schedule that belongs in the task itself.
 *
 * The day of month and day of week fields follow the crontab rule that surprises everybody the
 * first time: when **both** are restricted, the expression matches a day satisfying **either** of
 * them, not both. `0 0 13 * 5` is "the 13th, and every Friday", not "Friday the 13th".
 */
class cron
{
    /**
     * Named expressions, expanded before parsing
     */
    private const SHORTHANDS = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
        '@always'   => '* * * * *',
    ];

    /**
     * Three letter month names, in the order the field numbers them
     */
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,  'may' => 5,  'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Three letter weekday names
     */
    private const DAYS = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    /**
     * Bounds of each field, in the order they appear
     */
    private const RANGES = [
        [0, 59],   // minute
        [0, 23],   // hour
        [1, 31],   // day of month
        [1, 12],   // month
        [0, 7],    // day of week, 7 being Sunday as well
    ];

    /**
     * Whether an expression is due at a point in time.
     *
     * @param string $expression Five fields, or one of the shorthands
     * @param int    $timestamp  Unix time; seconds are ignored, cron resolves to the minute
     *
     * @return bool
     * @throws config_exception When the expression cannot be read
     */
    public static function due(string $expression, int $timestamp): bool
    {
        $fields = self::parse($expression);

        $now = [
            (int) date('i', $timestamp),   // minute
            (int) date('G', $timestamp),   // hour
            (int) date('j', $timestamp),   // day of month
            (int) date('n', $timestamp),   // month
            (int) date('w', $timestamp),   // day of week, 0 = Sunday
        ];

        // Minute, hour and month always have to match
        foreach ( [0, 1, 3] as $i )
        {
            if ( !in_array($now[$i], $fields[$i], true) )
            {
                return false;
            }
        }

        $dom_restricted = count($fields[2]) !== 31;
        $dow_restricted = count($fields[4]) !== 8;

        $dom_match = in_array($now[2], $fields[2], true);
        // The parsed weekday set holds both 0 and 7 for Sunday, and date('w') only ever answers 0
        $dow_match = in_array($now[4], $fields[4], true);

        // Both restricted: either one is enough, which is what crontab(5) says and what every
        // implementation does
        if ( $dom_restricted && $dow_restricted )
        {
            return $dom_match || $dow_match;
        }

        return $dom_match && $dow_match;
    }

    /**
     * The next minute at or after a point in time when an expression is due.
     *
     * Used by `schedule:list` to show when a task will next run. It steps minute by minute rather
     * than solving the fields, and gives up after four years -- the longest gap a valid expression
     * can have is a leap day, and an expression with no match at all (31 February) has to end
     * somewhere.
     *
     * @param string $expression
     * @param int    $timestamp Unix time to search from, inclusive of its own minute
     *
     * @return int|null  Unix time of the next due minute, null when there is none
     * @throws config_exception When the expression cannot be read
     */
    public static function next(string $expression, int $timestamp)
    {
        // Truncated to the minute: cron has no finer resolution and neither does the search
        $minute = $timestamp - ($timestamp % 60);
        $limit  = 4 * 366 * 24 * 60;

        for ( $i = 0; $i < $limit; $i++ )
        {
            if ( self::due($expression, $minute) )
            {
                return $minute;
            }

            $minute += 60;
        }

        return null;
    }

    /**
     * Expand an expression into the set of values each field accepts.
     *
     * @param string $expression
     *
     * @return array<int, array<int, int>>  Five sorted lists of allowed values
     * @throws config_exception When the expression cannot be read
     */
    public static function parse(string $expression): array
    {
        static $cache = [];

        $key = strtolower(trim($expression));

        if ( isset($cache[$key]) )
        {
            return $cache[$key];
        }

        $text = self::SHORTHANDS[$key] ?? $key;

        if ( $text !== '' && $text[0] === '@' )
        {
            throw new config_exception(['unknown cron shorthand ' . $expression], 1201);
        }

        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $parts = is_array($parts) ? $parts : [];

        if ( count($parts) !== 5 )
        {
            throw new config_exception(
                ['a cron expression has five fields, got ' . count($parts) . ' in ' . $expression],
                1201
            );
        }

        $fields = [];

        foreach ( $parts as $i => $part )
        {
            $fields[$i] = self::_field((string) $part, (int) $i, $expression);
        }

        return $cache[$key] = $fields;
    }

    /**
     * The values one field accepts.
     *
     * @param string $field
     * @param int    $index      Which of the five, so the bounds and the names are the right ones
     * @param string $expression Whole expression, for the error message
     *
     * @return array<int, int>
     * @throws config_exception
     */
    private static function _field(string $field, int $index, string $expression): array
    {
        list($min, $max) = self::RANGES[$index];

        $values = [];

        foreach ( explode(',', $field) as $piece )
        {
            $piece = trim($piece);

            if ( $piece === '' )
            {
                throw new config_exception(['empty field in cron expression ' . $expression], 1201);
            }

            $step = 1;

            if ( strpos($piece, '/') !== false )
            {
                list($piece, $step_text) = explode('/', $piece, 2);
                $step = (int) $step_text;

                if ( $step < 1 )
                {
                    throw new config_exception(['step must be 1 or more in ' . $expression], 1201);
                }
            }

            if ( $piece === '*' || $piece === '' )
            {
                $from = $min;
                $to   = $max;
            }
            elseif ( strpos($piece, '-') !== false )
            {
                list($from_text, $to_text) = explode('-', $piece, 2);
                $from = self::_value($from_text, $index, $expression);
                $to   = self::_value($to_text, $index, $expression);
            }
            else
            {
                $from = $to = self::_value($piece, $index, $expression);
            }

            if ( $from > $to )
            {
                throw new config_exception(['reversed range in cron expression ' . $expression], 1201);
            }

            for ( $value = $from; $value <= $to; $value += $step )
            {
                $values[] = $value;
            }
        }

        // Sunday is both 0 and 7, and a caller matching on date('w') only ever sees 0
        if ( $index === 4 && in_array(7, $values, true) && !in_array(0, $values, true) )
        {
            $values[] = 0;
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * One number, name or bound of a range.
     *
     * @param string $text
     * @param int    $index
     * @param string $expression
     *
     * @return int
     * @throws config_exception
     */
    private static function _value(string $text, int $index, string $expression): int
    {
        list($min, $max) = self::RANGES[$index];

        $text = strtolower(trim($text));

        if ( $index === 3 && isset(self::MONTHS[$text]) )
        {
            return self::MONTHS[$text];
        }

        if ( $index === 4 && isset(self::DAYS[$text]) )
        {
            return self::DAYS[$text];
        }

        if ( !preg_match('/^\d+$/', $text) )
        {
            throw new config_exception(
                ['cannot read "' . $text . '" in cron expression ' . $expression],
                1201
            );
        }

        $value = (int) $text;

        if ( $value < $min || $value > $max )
        {
            throw new config_exception(
                [$value . ' is outside ' . $min . '-' . $max . ' in cron expression ' . $expression],
                1201
            );
        }

        return $value;
    }
}
