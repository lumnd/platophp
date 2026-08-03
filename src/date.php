<?php

/**
 * Date and time helpers: timezone conversion, durations and calendar ranges
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

class date
{
    /**
     * Settings the `date` section does not name.
     *
     * An empty `timezone` means the process timezone, which plato::registry() has already handed
     * to date_default_timezone_set(). Naming one here decouples what the application displays from
     * what the process is set to, which is what a deployment serving several regions needs.
     */
    private const DEFAULTS = [
        'timezone' => '',
        'format'   => 'Y-m-d H:i:s',
    ];

    /**
     * Suffixes duration() appends to each unit.
     *
     * Deliberately English abbreviations rather than words: the framework ships no translations,
     * so a caller wanting its own wording passes the array in. Keys are fixed, values are free.
     */
    public const UNITS = [
        'days'    => 'd',
        'hours'   => 'h',
        'minutes' => 'm',
        'seconds' => 's',
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * The effective settings, read from the `date` section on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('date', []) + self::DEFAULTS;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand this class its settings instead of letting it read config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `date` section
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$_config = null;
    }

    /**
     * Build a moment out of whatever the caller has.
     *
     * A timestamp names an instant, so $timezone only decides how it is displayed afterwards. A
     * string names a wall clock reading, so $timezone is how that reading is *interpreted* -- the
     * same '2026-07-31 09:00' is a different instant in every zone.
     *
     * @param \DateTimeInterface|int|string|null $datetime Timestamp, parsable string, or null for now
     * @param string|null                        $timezone Zone name, null for the configured one
     *
     * @return \DateTimeImmutable
     * @throws \Exception When $datetime cannot be parsed, or $timezone is not a known zone
     */
    public static function make($datetime = null, ?string $timezone = null): \DateTimeImmutable
    {
        $zone = self::zone($timezone);

        if ( $datetime instanceof \DateTimeInterface )
        {
            $moment = new \DateTimeImmutable($datetime->format('Y-m-d H:i:s.u'), $datetime->getTimezone());

            return $moment->setTimezone($zone);
        }

        if ( $datetime === null || $datetime === '' )
        {
            return new \DateTimeImmutable('now', $zone);
        }

        // '@1753920000' is always read as UTC, whatever zone is passed alongside it, so the
        // display zone is applied afterwards rather than at construction
        if ( is_int($datetime) || ctype_digit(ltrim($datetime, '-')) )
        {
            return (new \DateTimeImmutable('@' . (int) $datetime))->setTimezone($zone);
        }

        return new \DateTimeImmutable((string) $datetime, $zone);
    }

    /**
     * Resolve a zone name: the argument, then the configured zone, then the process zone.
     *
     * @param string|null $timezone
     *
     * @return \DateTimeZone
     * @throws \Exception When the name is not a known zone
     */
    public static function zone(?string $timezone = null): \DateTimeZone
    {
        $name = ($timezone === null || $timezone === '') ? (string) self::config('timezone') : $timezone;

        return new \DateTimeZone($name === '' ? date_default_timezone_get() : $name);
    }

    /**
     * Now, in the configured or the given zone.
     *
     * @param string|null $timezone
     *
     * @return \DateTimeImmutable
     * @throws \Exception When $timezone is not a known zone
     */
    public static function now(?string $timezone = null): \DateTimeImmutable
    {
        return self::make(null, $timezone);
    }

    /**
     * Format a moment.
     *
     * @param \DateTimeInterface|int|string|null $datetime
     * @param string|null                        $format   Null for the configured format
     * @param string|null                        $timezone
     *
     * @return string
     * @throws \Exception When $datetime cannot be parsed, or $timezone is not a known zone
     */
    public static function format($datetime = null, ?string $format = null, ?string $timezone = null): string
    {
        return self::make($datetime, $timezone)->format($format ?? (string) self::config('format'));
    }

    /**
     * Unix timestamp of a moment.
     *
     * @param \DateTimeInterface|int|string|null $datetime
     * @param string|null                        $timezone Zone the string is read in
     *
     * @return int
     * @throws \Exception When $datetime cannot be parsed, or $timezone is not a known zone
     */
    public static function timestamp($datetime = null, ?string $timezone = null): int
    {
        return self::make($datetime, $timezone)->getTimestamp();
    }

    /**
     * Read a moment in one zone and render it in another.
     *
     * @param \DateTimeInterface|int|string|null $datetime
     * @param string|null                        $to       Zone to render in, null for the configured one
     * @param string|null                        $format   Null for the configured format
     * @param string|null                        $from     Zone a string is read in, null for the configured one
     *
     * @return string
     * @throws \Exception When $datetime cannot be parsed, or a zone name is not known
     */
    public static function convert(
        $datetime = null,
        ?string $to = null,
        ?string $format = null,
        ?string $from = null
    ): string {
        return self::make($datetime, $from)
            ->setTimezone(self::zone($to))
            ->format($format ?? (string) self::config('format'));
    }

    /**
     * Apply a relative modification, e.g. '+1 month', '-7 days', 'last day of this month'.
     *
     * @param \DateTimeInterface|int|string|null $datetime
     * @param string                             $modify   Anything DateTimeImmutable::modify() takes
     * @param string|null                        $format   Null for the configured format
     * @param string|null                        $timezone
     *
     * @return string
     * @throws \Exception                When $datetime cannot be parsed, or $timezone is not known
     * @throws \InvalidArgumentException When $modify is not a relative format PHP understands
     */
    public static function modify(
        $datetime,
        string $modify,
        ?string $format = null,
        ?string $timezone = null
    ): string {
        $moment = self::make($datetime, $timezone);

        // A bad modifier reports itself two ways depending on the runtime: false up to 8.2, and a
        // DateMalformedStringException from 8.3 on. Checking the format up front makes both
        // unreachable and the failure the same everywhere -- modify() and strtotime() are the same
        // parser, so what one accepts the other does. The check below stays for the 8.2 shape
        if ( strtotime($modify, 0) === false )
        {
            throw new \InvalidArgumentException('Unknown relative date format: ' . $modify);
        }

        /** @var \DateTimeImmutable|false $modified */
        $modified = $moment->modify($modify);

        if ( $modified === false )
        {
            throw new \InvalidArgumentException('Unknown relative date format: ' . $modify);
        }

        return $modified->format($format ?? (string) self::config('format'));
    }

    /**
     * Current time in milliseconds.
     *
     * @return int
     */
    public static function millis(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Split a number of seconds into days, hours, minutes and seconds.
     *
     * The parts are always positive; `negative` says which way round the span was.
     *
     * @param int $seconds
     *
     * @return array{negative: bool, days: int, hours: int, minutes: int, seconds: int}
     */
    public static function parts(int $seconds): array
    {
        $negative = $seconds < 0;
        $seconds  = abs($seconds);

        return [
            'negative' => $negative,
            'days'     => intdiv($seconds, 86400),
            'hours'    => intdiv($seconds % 86400, 3600),
            'minutes'  => intdiv($seconds % 3600, 60),
            'seconds'  => $seconds % 60,
        ];
    }

    /**
     * Render a number of seconds as a duration, e.g. '1d 2h 3m 4s'.
     *
     * Leading zero units are dropped, so 90 seconds is '1m 30s' rather than '0d 0h 1m 30s'; the
     * smallest unit is always kept, so 0 is '0s'. A caller wanting other wording passes $units:
     * duration(90, ['days' => '天', 'hours' => '小时', 'minutes' => '分', 'seconds' => '秒'], '')
     *
     * @param int                   $seconds
     * @param array<string, string> $units     Suffix per unit, see self::UNITS for the keys
     * @param string                $separator Between units
     *
     * @return string
     */
    public static function duration(int $seconds, array $units = self::UNITS, string $separator = ' '): string
    {
        $parts  = self::parts($seconds);
        $units += self::UNITS;
        $out    = [];

        foreach ( ['days', 'hours', 'minutes', 'seconds'] as $unit )
        {
            if ( $out === [] && $parts[$unit] === 0 && $unit !== 'seconds' )
            {
                continue;
            }

            $out[] = $parts[$unit] . $units[$unit];
        }

        return ($parts['negative'] ? '-' : '') . implode($separator, $out);
    }

    /**
     * Whole calendar days between two dates, always positive.
     *
     * Both sides are taken down to midnight first, so this counts date boundaries crossed rather
     * than 86400 second blocks: 23:00 Monday to 01:00 Tuesday is one day, not zero.
     *
     * @param \DateTimeInterface|int|string      $from
     * @param \DateTimeInterface|int|string|null $to   Null for today
     * @param string|null                        $timezone
     *
     * @return int
     * @throws \Exception When either side cannot be parsed, or $timezone is not known
     */
    public static function diff_days($from, $to = null, ?string $timezone = null): int
    {
        $start = self::make($from, $timezone)->setTime(0, 0, 0);
        $end   = self::make($to, $timezone)->setTime(0, 0, 0);

        return (int) $start->diff($end)->days;
    }

    /**
     * First and last day of an ISO week.
     *
     * ISO weeks start on Monday and week 1 is the one holding the first Thursday of the year, so
     * early January can belong to the previous year's week 52 or 53.
     *
     * @param int         $week
     * @param int|null    $year Null for the current year
     * @param string|null $timezone
     *
     * @return array{start: string, end: string} Both 'Y-m-d'
     * @throws \Exception When $timezone is not known
     */
    public static function week_range(int $week, ?int $year = null, ?string $timezone = null): array
    {
        $moment = self::now($timezone)->setISODate($year ?? (int) self::now($timezone)->format('Y'), $week);

        return [
            'start' => $moment->format('Y-m-d'),
            'end'   => $moment->add(new \DateInterval('P6D'))->format('Y-m-d'),
        ];
    }

    /**
     * First and last day of the month a date falls in.
     *
     * @param \DateTimeInterface|int|string|null $date Null for the current month
     * @param string|null                        $timezone
     *
     * @return array{start: string, end: string} Both 'Y-m-d'
     * @throws \Exception When $date cannot be parsed, or $timezone is not known
     */
    public static function month_range($date = null, ?string $timezone = null): array
    {
        $moment = self::make($date, $timezone);

        return [
            'start' => $moment->format('Y-m-01'),
            'end'   => $moment->format('Y-m-t'),
        ];
    }

    /**
     * Seconds $timezone is ahead of $against, right now.
     *
     * Now, and not some fixed date, because the answer moves with daylight saving on either side.
     *
     * @param string|null $timezone Null for the configured zone
     * @param string|null $against  Null for the configured zone
     *
     * @return int Negative when $timezone is behind $against
     * @throws \Exception When either name is not a known zone
     */
    public static function offset(?string $timezone = null, ?string $against = null): int
    {
        $now = new \DateTimeImmutable('now');

        return self::zone($timezone)->getOffset($now) - self::zone($against)->getOffset($now);
    }

    /**
     * Whether a string is a real date in one of the given formats.
     *
     * Round trips through the format rather than asking strtotime(), which accepts '2026-02-31'
     * and quietly answers March 3rd.
     *
     * @param string        $value
     * @param string[]|string $formats One format or a list of them; any match passes
     *
     * @return bool
     */
    public static function valid(string $value, $formats = ['Y-m-d', 'Y-m-d H:i:s']): bool
    {
        foreach ( (array) $formats as $format )
        {
            $moment = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ( $moment !== false && $moment->format($format) === $value )
            {
                return true;
            }
        }

        return false;
    }
}
