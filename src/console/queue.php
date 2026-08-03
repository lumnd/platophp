<?php

/**
 * Console command: run a queue consumer, and report what a queue is holding
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\pool;
use plato\queue\queue as facade;
use plato\queue\worker;
// plato\worker is the process identity facade; the queue's own worker already holds the short name
use plato\worker as process;

/**
 * The queue verbs.
 *
 * `queue:work` is a foreground process on purpose. It consumes until a signal, a job count or a
 * lifetime tells it to stop, and then exits -- keeping it running across a crash or a deploy is a
 * process manager's job, and every deployment already has one:
 *
 *     [Service]
 *     ExecStart=/usr/bin/php /srv/app/vendor/bin/plato queue:work --queue=emails --workers=4
 *     Restart=always
 *     KillSignal=SIGTERM
 *     TimeoutStopSec=40
 *
 * `--workers=N` is the one part of that a unit file cannot express, because it is inside the unit:
 * N consumers competing for the same queue, under a plato\pool supervisor that refills a slot when
 * a consumer exits on `--max-jobs` or dies. Without it, or with `--workers=1`, this is a single
 * process that never forks and needs no pcntl.
 *
 * Note that competing consumers is a property of the *queue*, not of the worker count: every
 * message goes to exactly one of the N. Redis pub/sub, where every subscriber gets every message,
 * is a different thing and is not what this consumes.
 *
 * `--once` is the other shape: consume what is there and exit, which is what a cron entry wants and
 * what makes the loop testable. Combined with `--workers`, the supervisor does not refill a slot --
 * N consumers drain the queue in parallel and the command returns when the last of them is done.
 */
class queue implements command
{
    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'queue:work'   => 'Consume messages until a signal, a job count or a lifetime stops it',
            'queue:status' => 'Report what a queue is holding: ready, delayed and failed',
            'queue:retry'  => 'Put failed messages back on their queue',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        if ( $name === 'queue:status' )
        {
            return '  --queue=NAME           Queue to report on, comma separated for several,'
                . ' default `default`'
                . PHP_EOL . '  --connection=NAME      Queue connection, default the configured one';
        }

        if ( $name === 'queue:retry' )
        {
            return '  --queue=NAME           Queue to retry, comma separated for several,'
                . ' default `default`'
                . PHP_EOL . '  --connection=NAME      Queue connection, default the configured one'
                . PHP_EOL . '  --limit=N              Most messages to put back per queue, 0 for all';
        }

        return '  --queue=NAME           Queue to read, comma separated for several in priority'
            . ' order, default `default`'
            . PHP_EOL . '  --connection=NAME      Queue connection, default the configured one'
            . PHP_EOL . '  --workers=N            Run N consumers under a supervisor, default 1'
            . ' (no fork)'
            . PHP_EOL . '  --once                 Stop as soon as a queue comes up empty'
            . PHP_EOL . '  --max-jobs=N           Exit after N messages, 0 for no limit'
            . PHP_EOL . '  --max-lifetime=N       Exit after N seconds, 0 for no limit'
            . PHP_EOL . '  --timeout-ms=N          How long one blocking read waits'
            . PHP_EOL . '  --grace=N              Seconds a worker gets to finish after SIGTERM,'
            . ' default 30';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        return [];
    }

    /**
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        if ( $name === 'queue:status' )
        {
            return self::_status();
        }

        if ( $name === 'queue:retry' )
        {
            return self::_retry();
        }

        return self::_work();
    }

    /**
     * Consume, in this process or in N of them.
     *
     * @return int
     */
    private static function _work(): int
    {
        $queues  = self::_queues();
        $once    = (bool) console::option('once', false);
        $workers = max(1, (int) (self::_int('workers') ?? 1));

        $options = [
            'connection'   => self::_connection(),
            'queues'       => $queues,
            'once'         => $once,
            'max_jobs'     => self::_int('max_jobs'),
            'max_lifetime' => self::_int('max_lifetime'),
            'timeout_ms'   => self::_int('timeout_ms'),
        ];

        console::line(sprintf(
            'Consuming %s on %s%s',
            implode(', ', $queues),
            get_class(facade::connection(self::_connection())),
            $workers > 1 ? sprintf(' in %d processes', $workers) : ''
        ));

        if ( $workers === 1 )
        {
            self::_report(worker::run($options));

            return console::OK;
        }

        // The driver is resolved above, in the master, but nothing has connected: the facade only
        // turned a config key into a class name, and pool::supervise() releases the registry before
        // its first fork anyway. Each consumer opens its own connection on its first pop()
        $code = pool::supervise(
            static function () use ($options): void
            {
                self::_report(worker::run($options), process::index());
            },
            $workers,
            [
                'grace' => (float) (self::_int('grace') ?? 30),
                // --once means drain and leave; refilling the slot would start a consumer that
                // finds the queue empty and exits, forever
                'restart' => !$once,
                'notify'  => static function (string $line): void
                {
                    console::line($line);
                },
            ]
        );

        return $code === 0 ? console::OK : console::FAILURE;
    }

    /**
     * Print what a consumer got through.
     *
     * @param array{processed: int, failed: int, released: int, stopped: string} $stats
     * @param int|null                                                           $worker Index when
     *                                                                                   there are several
     *
     * @return void
     */
    private static function _report(array $stats, ?int $worker = null): void
    {
        console::line(sprintf(
            '%sStopped on %s: %d processed, %d released, %d failed',
            $worker === null ? '' : sprintf('worker %d: ', $worker),
            $stats['stopped'],
            $stats['processed'],
            $stats['released'],
            $stats['failed']
        ));
    }

    /**
     * Report the depth of every queue named.
     *
     * @return int
     */
    private static function _status(): int
    {
        $driver = facade::connection(self::_connection());

        foreach ( self::_queues() as $queue )
        {
            $line = sprintf('%-20s ready=%-8d', $queue, $driver->size($queue));

            // Delayed and failed counts are not part of the driver contract; a driver that keeps
            // them says so by answering pending()
            if ( method_exists($driver, 'pending') )
            {
                $pending = $driver->pending($queue);
                $line   .= sprintf('delayed=%-8d failed=%-8d', $pending['delayed'] ?? 0, $pending['failed'] ?? 0);
            }

            console::line($line);
        }

        return console::OK;
    }

    /**
     * Put failed messages back on the queues named.
     *
     * @return int
     */
    private static function _retry(): int
    {
        $driver = facade::connection(self::_connection());

        // Not part of the driver contract: a driver that keeps failed messages somewhere it can
        // read back says so by answering retry_failed()
        if ( !method_exists($driver, 'retry_failed') )
        {
            console::fail(get_class($driver) . ' cannot put failed messages back');

            return console::FAILURE;
        }

        $limit = (int) (self::_int('limit') ?? 0);
        $total = 0;

        foreach ( self::_queues() as $queue )
        {
            $moved  = (int) $driver->retry_failed($queue, $limit);
            $total += $moved;

            console::line(sprintf('%-20s %d put back', $queue, $moved));
        }

        return $total >= 0 ? console::OK : console::FAILURE;
    }

    /**
     * Queues named on the command line, `default` when none were.
     *
     * @return array<int, string>
     */
    private static function _queues(): array
    {
        $option = console::option('queue', 'default');
        $names  = is_string($option) ? explode(',', $option) : ['default'];
        $names  = array_values(array_filter(array_map('trim', $names), static function ($name)
        {
            return $name !== '';
        }));

        return $names ?: ['default'];
    }

    /**
     * Named connection requested on the command line, or null for the configured default.
     */
    private static function _connection(): ?string
    {
        $connection = console::option('connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * A numeric option, left to the configuration when it was not given.
     *
     * @param string $name Option name
     *
     * @return int|null
     */
    private static function _int(string $name)
    {
        $value = console::option($name);

        return is_string($value) && $value !== '' ? (int) $value : null;
    }
}
