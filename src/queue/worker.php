<?php

/**
 * Queue worker: the consume loop, with the retry policy and the stop conditions
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

use plato\config;
use plato\exception\queue_exception;
use plato\log;
use plato\plato;
use Throwable;

/**
 * One process consuming one or more queues.
 *
 * The drivers deliberately know nothing about retries: where a message lives between two attempts
 * is a driver's business, how many attempts it gets is a policy, and a policy that lives in three
 * drivers is three policies. So this is the only place that counts attempts, works out a backoff
 * and decides that a message has had enough -- see the `retry` section of config/queue.php.
 *
 * What stops a worker, all of it configurable and all of it voluntary:
 *
 *   SIGTERM / SIGINT / SIGQUIT   the current message is finished first, then the loop ends. This
 *                                is what a deploy sends; killing a worker mid-message loses the
 *                                message on an at-most-once driver
 *   max_jobs                     exit after this many messages so that whatever leaks in the
 *                                application goes with the process. The supervisor starts another
 *   max_lifetime                 same, by the clock
 *   once                         stop as soon as a queue comes up empty. What a cron driven
 *                                consumer and a test want
 *
 * Supervision is not this class's job. This is one loop in one process; running several of them and
 * putting a fresh one in the place of one that exited is plato\pool, which `queue:work --workers=N`
 * wraps this in -- and the two are built to fit: `max_jobs` and `max_lifetime` exist so a consumer
 * can end on purpose and take whatever leaks with it, which is only useful if something starts
 * another one. Everything outside the unit -- starting it at boot, restarting it after a crash,
 * daemonising, log rotation -- stays with systemd, supervisord or the container runtime.
 *
 * The handler is where the application's work happens. Either it is given one -- any callable,
 * handed the message -- or the payload names a controller action, `['ct' => 'mail', 'ac' => 'send']`
 * plus whatever the action reads through req::, and the worker dispatches it the way an http request
 * would be. The worker uses plato::handle(), so a response value is not emitted to stdout.
 *
 * Either way the message is served behind a request boundary: `plato::reset_request()` runs before
 * every message, not only before a routed one. A callable handler is still application code running
 * in a process that will serve thousands more messages, so it gets the same moved-on clock, the same
 * fresh request id, and the same cleared statics -- what leaks without it leaks for both.
 */
class worker
{
    /**
     * Whether a signal asked the loop to stop
     *
     * @var bool
     */
    private static $_stop = false;

    /**
     * Whether the signal handlers are installed
     *
     * @var bool
     */
    private static $_signals = false;

    /**
     * Consume until one of the stop conditions is met.
     *
     * @param array<string, mixed> $options queues, timeout_ms, migrate_interval_ms, migrate_limit,
     *                                      max_jobs, max_lifetime, max_attempts, backoff,
     *                                      inline_backoff_max, once, handler. Anything absent comes
     *                                      from config/queue.php
     *
     * @return array{processed: int, failed: int, released: int, stopped: string}
     */
    public static function run(array $options = []): array
    {
        $settings = self::_settings($options);
        $queues   = $settings['queues'];
        $driver   = queue::connection($settings['connection']);

        if ( !$queues )
        {
            throw new queue_exception('a worker needs at least one queue to read from');
        }

        self::_listen();
        self::$_stop = false;

        $started  = microtime(true);
        $stats    = ['processed' => 0, 'failed' => 0, 'released' => 0, 'stopped' => 'once'];
        $migrated = 0.0;

        while ( true )
        {
            self::_dispatch_signals();

            if ( self::$_stop )
            {
                $stats['stopped'] = 'signal';
                break;
            }

            $now = microtime(true);

            if ( $now >= $migrated )
            {
                if ( $driver instanceof delayable )
                {
                    $driver->migrate_delayed($queues, $settings['migrate_limit']);
                }
                $migrated = $now + $settings['migrate_interval_ms'] / 1000;
            }

            $message = $driver->pop($queues, $settings['timeout_ms']);

            if ( $message === null )
            {
                if ( $settings['once'] )
                {
                    $stats['stopped'] = 'once';
                    break;
                }

                if ( self::_outlived($started, $settings) )
                {
                    $stats['stopped'] = 'max_lifetime';
                    break;
                }

                continue;
            }

            $outcome = self::_process($message, $settings, $driver);

            $stats['processed']++;

            if ( $outcome !== '' )
            {
                $stats[$outcome]++;
            }

            if ( $settings['max_jobs'] > 0 && $stats['processed'] >= $settings['max_jobs'] )
            {
                $stats['stopped'] = 'max_jobs';
                break;
            }

            if ( self::_outlived($started, $settings) )
            {
                $stats['stopped'] = 'max_lifetime';
                break;
            }
        }

        return $stats;
    }

    /**
     * Ask the loop to stop after the message it is on.
     *
     * Public so an application that installed its own signal handlers can say so.
     *
     * @return void
     */
    public static function stop(): void
    {
        self::$_stop = true;
    }

    /**
     * Run one message: hand it to the handler, then acknowledge or retry it.
     *
     * @param message              $message
     * @param array<string, mixed> $settings
     *
     * @return string  '' on success, 'released' or 'failed' otherwise
     */
    private static function _process(message $message, array $settings, driver $driver): string
    {
        // One message is one unit of work, the way one request is under php-fpm, and a resident
        // worker forgets nothing between two of them. This is the request boundary: it moves the
        // clock on, rotates the request id, and clears every static the previous message left
        // behind -- plato::reset_request() owns the list and says what each entry leaks without it.
        // It belongs here and not next to the dispatch, so that a callable handler is given the
        // same boundary a routed payload is
        plato::reset_request();

        // The message id goes beside the request id because it is the better correlation key of the
        // two: it survives a release and a retry on another worker
        log::context(['job' => $message->id()]);

        try
        {
            self::_call($message, $settings['handler']);

            if ( !$driver->ack($message) )
            {
                throw new queue_exception(sprintf(
                    'queue message %s could not be acknowledged on %s',
                    $message->id(),
                    $message->queue()
                ));
            }

            log::info(sprintf('queue: %s done on %s', $message->id(), $message->queue()));

            return '';
        }
        catch ( Throwable $e )
        {
            return self::_retry($message, $e, $settings, $driver);
        }
        finally
        {
            // The id belongs to the message, not to the worker; anything logged between messages
            // must not claim to belong to the one that just finished
            log::forget_context(['job']);
        }
    }

    /**
     * Give the message to the handler, or to the controller action its payload names.
     *
     * @param message       $message
     * @param callable|null $handler
     *
     * @return void
     * @throws queue_exception When there is neither a handler nor a routable payload
     */
    private static function _call(message $message, $handler): void
    {
        if ( $handler !== null )
        {
            call_user_func($handler, $message);

            return;
        }

        $payload = $message->payload();

        if ( is_array($payload) && isset($payload['ct']) )
        {
            // handle() answers false under CLI rather than throwing when the route goes nowhere -- an
            // unknown controller, an action that is not one. Left alone, that would count a message
            // nobody ran as done
            if ( plato::handle($payload) === false )
            {
                throw new queue_exception(sprintf(
                    'queue message %s could not be dispatched to %s:%s',
                    $message->id(),
                    (string) $payload['ct'],
                    (string) ($payload['ac'] ?? '')
                ));
            }

            return;
        }

        throw new queue_exception(sprintf(
            'queue message %s has no handler: pass one to worker::run(), set worker.handler in'
            . ' config/queue.php, or push a payload naming a controller action',
            $message->id()
        ));
    }

    /**
     * Count the failed delivery and either put the message back or give up on it.
     *
     * @param message              $message
     * @param Throwable            $e
     * @param array<string, mixed> $settings
     *
     * @return string  'released' or 'failed'
     */
    private static function _retry(message $message, Throwable $e, array $settings, driver $driver): string
    {
        $message->attempted();

        if ( $message->attempts() >= $settings['max_attempts'] )
        {
            if ( !$driver->fail($message, $e->getMessage()) )
            {
                throw new queue_exception(sprintf(
                    'queue message %s could not be moved to the failed destination on %s',
                    $message->id(),
                    $message->queue()
                ), 0, $e);
            }

            log::error(sprintf(
                'queue: %s failed for good on %s after %d attempts: %s',
                $message->id(),
                $message->queue(),
                $message->attempts(),
                $e->getMessage()
            ));

            return 'failed';
        }

        $delay = self::_backoff($message->attempts(), $settings['backoff']);

        // A driver that cannot hold a message back leaves the waiting to the consumer, which blocks
        // it -- so that wait is capped separately, and the rest of the backoff is given up
        if ( !$driver instanceof delayable )
        {
            $wait = min($delay, $settings['inline_backoff_max']);

            if ( $wait > 0 )
            {
                sleep($wait);
            }

            $delay = 0;
        }

        if ( !$driver->release($message, $delay) )
        {
            throw new queue_exception(sprintf(
                'queue message %s could not be released on %s',
                $message->id(),
                $message->queue()
            ), 0, $e);
        }

        log::warning(sprintf(
            'queue: %s released on %s for attempt %d in %ds: %s',
            $message->id(),
            $message->queue(),
            $message->attempts() + 1,
            $delay,
            $e->getMessage()
        ));

        return 'released';
    }

    /**
     * Seconds to wait before the next attempt.
     *
     * The last entry of the list is reused once it runs out, so a two entry backoff does not turn
     * into an immediate retry loop on the third attempt.
     *
     * @param int              $attempts Deliveries so far
     * @param array<int, int>  $backoff  Configured waits
     *
     * @return int
     */
    private static function _backoff(int $attempts, array $backoff): int
    {
        if ( !$backoff )
        {
            return 0;
        }

        $index = min(max($attempts, 1), count($backoff)) - 1;

        return max(0, (int) array_values($backoff)[$index]);
    }

    /**
     * Whether the process has been up longer than it is allowed to be.
     *
     * @param float                $started  microtime the loop began
     * @param array<string, mixed> $settings
     *
     * @return bool
     */
    private static function _outlived(float $started, array $settings): bool
    {
        return $settings['max_lifetime'] > 0
            && (microtime(true) - $started) >= $settings['max_lifetime'];
    }

    /**
     * Merge the caller's options over config/queue.php.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function _settings(array $options): array
    {
        $file   = (array) config::instance('queue')->get();
        $retry  = (array) ($file['retry'] ?? []);
        $work   = (array) ($file['worker'] ?? []);
        $queues = $options['queues'] ?? ($work['queues'] ?? ['default']);
        $handler = $options['handler'] ?? ($work['handler'] ?? null);

        return [
            'connection'          => isset($options['connection']) ? (string) $options['connection'] : null,
            'queues'              => self::_names($queues),
            'timeout_ms'          => (int) ($options['timeout_ms'] ?? $work['timeout_ms'] ?? 1000),
            'migrate_interval_ms' => (int) ($options['migrate_interval_ms'] ?? $work['migrate_interval_ms'] ?? 1000),
            'migrate_limit'       => (int) ($options['migrate_limit'] ?? $work['migrate_limit'] ?? 128),
            'max_jobs'            => (int) ($options['max_jobs'] ?? $work['max_jobs'] ?? 0),
            'max_lifetime'        => (int) ($options['max_lifetime'] ?? $work['max_lifetime'] ?? 0),
            'max_attempts'        => max(1, (int) ($options['max_attempts'] ?? $retry['max_attempts'] ?? 3)),
            'backoff'             => (array) ($options['backoff'] ?? $retry['backoff'] ?? [1, 5, 30]),
            'inline_backoff_max'  => (int) ($options['inline_backoff_max'] ?? $retry['inline_backoff_max'] ?? 5),
            'once'                => (bool) ($options['once'] ?? false),
            'handler'             => is_callable($handler) ? $handler : null,
        ];
    }

    /**
     * Queue names, as strings, with the empty ones dropped.
     *
     * @param mixed $queues One name or several
     *
     * @return array<int, string>
     */
    private static function _names($queues): array
    {
        $names = [];

        foreach ( (array) $queues as $queue )
        {
            $queue = trim((string) $queue);

            if ( $queue !== '' )
            {
                $names[] = $queue;
            }
        }

        return $names;
    }

    /**
     * Install the signal handlers, once.
     *
     * Nothing is done in the handler beyond setting a flag: a message being processed when the
     * signal arrives is finished first, because an at-most-once driver has already handed it over
     * and there is nothing left to redeliver.
     *
     * @return void
     */
    private static function _listen(): void
    {
        if ( self::$_signals || !function_exists('pcntl_signal') )
        {
            return;
        }

        self::$_signals = true;

        foreach ( [SIGTERM, SIGINT, SIGQUIT] as $signal )
        {
            pcntl_signal($signal, static function ()
            {
                self::$_stop = true;
            });
        }
    }

    /**
     * Run whatever signal handlers are due.
     *
     * @return void
     */
    private static function _dispatch_signals(): void
    {
        if ( function_exists('pcntl_signal_dispatch') )
        {
            pcntl_signal_dispatch();
        }
    }
}
