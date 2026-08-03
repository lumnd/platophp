<?php

/**
 * Foreground process supervisor: forks N workers, keeps them up, stops them together
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use Exception;
use Throwable;

/**
 * A master process that forks N children, runs the same callable in each of them, and keeps that
 * many running until something tells it to stop.
 *
 *     pool::supervise(function (int $index)
 *     {
 *         while ( !pool::stopping() )
 *         {
 *             // one worker's loop
 *         }
 *     }, 4);
 *
 * It blocks, and it stays in the foreground. **This is not a daemon and will not become one**:
 * there is no fork-into-the-background, no pid file, no `stop` / `restart` / `reload` / `status`
 * verb and no rolling reload. Every deployment already has something that does all of that, and
 * does it better than a framework can -- so the shape here is the one a process manager wants to
 * supervise, not a second process manager competing with it:
 *
 *     [Service]
 *     ExecStart=/usr/bin/php /srv/app/vendor/bin/plato queue:work --queue=emails --workers=4
 *     Restart=always
 *     KillSignal=SIGTERM
 *     TimeoutStopSec=40
 *
 * What is left is the half systemd cannot do for you, because it is inside one unit: fork a fixed
 * number of children, notice when one of them ends, and put another in its place.
 *
 * **Signals.** SIGTERM, SIGINT and SIGQUIT stop the pool. The master forwards SIGTERM to every
 * child, stops refilling slots, and waits `grace` seconds for them to leave on their own before
 * SIGKILLing whatever is left. A child gets the same handler, so `stopping()` answers true inside
 * it and a loop that checks it drains and returns. A callable that installs signal handlers of its
 * own replaces that one and takes on the job of stopping itself -- `queue\worker::run()` does
 * exactly this, and finishes the message it is on before it returns.
 *
 * **Restarts.** A child that ends is replaced, which is what makes `max_jobs` / `max_lifetime`
 * usable: the worker exits on purpose to take its leaked memory with it, and a fresh one is up
 * within the poll interval. A child that ends having lived less than `healthy` seconds is held back
 * by `backoff` first, and if it exits non-zero that quickly `max_crashes` times in a row, the master
 * gives up on the whole pool and returns 1 rather than spinning at fork speed. A worker that keeps
 * dying is a bug the supervisor cannot fix, and a supervisor that hides it costs you the CPU as well.
 *
 * **The fork contract.** The master releases everything plato\runtime holds before the first fork,
 * so a child inherits no open socket or file handle and opens its own on first use; it then
 * announces the fork to the registry and reseeds mt_rand(), which fork otherwise copies along with
 * everything else. Two things stay the caller's problem: a resource the callable's *enclosing scope*
 * opened before supervise() was called, and any child of the host process that is not this pool's --
 * the master reaps its own pids by number, never with a wildcard wait, but it cannot stop a SIGCHLD
 * handler the host installed from reaping them first, which makes a clean exit look like a signal
 * death. Do not supervise from a process that reaps children of its own.
 *
 * CLI only: pcntl and posix are not available under any web SAPI.
 */
class pool
{
    /**
     * Settings and their defaults; supervise() takes the same keys.
     *
     * grace        seconds the master waits after SIGTERM before it SIGKILLs what is left
     * restart      whether a child that ends is replaced at all
     * backoff      seconds a slot is held empty after a child that did not stay up
     * healthy      seconds a child has to live for its slot to count as settled
     * max_crashes  consecutive short non-zero exits of one slot before the pool gives up
     * poll         seconds between reap sweeps, and so the worst case restart and stop latency
     * notify       callable(string $line), told when a worker starts, ends or is given up on
     */
    private const DEFAULTS = [
        'grace'       => 30.0,
        'restart'     => true,
        'backoff'     => 1.0,
        'healthy'     => 10.0,
        'max_crashes' => 5,
        'poll'        => 0.1,
        'notify'      => null,
    ];

    /**
     * Workers the running supervise() asked for, 0 when none is running.
     *
     * The master's own bookkeeping -- how many slots to keep filled. Which worker a *child* is
     * belongs to plato\worker, which _child() claims before it runs anything.
     *
     * @var int
     */
    private static $_count = 0;

    /**
     * Whether a signal has asked this process to stop
     *
     * @var bool
     */
    private static $_stop = false;

    /**
     * Whether this process is the master of a running pool. Cleared in a child, which inherits it
     * along with everything else and is not one.
     *
     * @var bool
     */
    private static $_supervising = false;

    /**
     * One entry per worker slot: the child filling it, when it started, how many short exits in a
     * row it has had, and when it may be refilled -- null for a slot that never will be.
     *
     * @var array<int, array{pid: int, started: float, crashes: int, retry_at: float|null}>
     */
    private static $_slots = [];

    /**
     * pid => slot index, for the reaper
     *
     * @var array<int, int>
     */
    private static $_pids = [];

    /**
     * Signal handlers supervise() replaced, restored before it returns
     *
     * @var array<int, callable|int>
     */
    private static $_saved = [];

    /**
     * Fork $workers children, run $work in each, and keep that many up until a signal stops it.
     *
     * Blocks. The callable is given the worker's index, counting from 0, and whatever it returns is
     * the child's exit code when it is an int -- anything else exits 0. Throwing exits 1 and writes
     * the exception to STDERR, because a child has nowhere better to put it.
     *
     * @param  callable             $work    Run in each child, receives the worker index
     * @param  int                  $workers How many children to keep running
     * @param  array<string, mixed> $options Any of the keys in DEFAULTS
     * @return int  0 when the pool stopped on a signal or ran out of work, 1 when it gave up on a
     *              worker that would not stay up
     * @throws Exception When pcntl or posix is missing, the worker count is below 1, a pool is
     *                   already running in this process, or a fork fails
     */
    public static function supervise(callable $work, $workers = 1, array $options = [])
    {
        // posix is a separate extension from pcntl and both are needed: fork to start the workers,
        // getpid/kill to tell master from child and to stop one that ignored SIGTERM
        if ( !function_exists('pcntl_fork') || !function_exists('posix_getpid') )
        {
            throw new Exception('pool requires the pcntl and posix extensions (CLI only)');
        }

        $workers = (int) $workers;

        if ( $workers < 1 )
        {
            throw new Exception('pool worker count must be at least 1');
        }

        // Nesting would leave the inner pool reaping the outer one's children, and the state below
        // is static because a child has to be able to read it after the fork
        if ( self::$_supervising )
        {
            throw new Exception('pool: a supervisor is already running in this process');
        }

        $settings = self::_settings($options);

        self::$_supervising = true;
        self::$_count       = $workers;
        self::$_stop        = false;
        self::$_pids        = [];
        self::$_slots       = [];

        for ($i = 0; $i < $workers; $i++)
        {
            self::$_slots[$i] = ['pid' => 0, 'started' => 0.0, 'crashes' => 0, 'retry_at' => 0.0];
        }

        self::_listen();

        // In the master, before the first fork: a child that inherits no descriptor cannot write
        // into one the master is using. Nothing reopens it here -- past this point the master only
        // forks and reaps, and the standard streams are not registry resources, so a child can
        // still print
        runtime::flush();

        $exit = 0;

        try
        {
            $exit = self::_loop($work, $settings);
        }
        finally
        {
            // Reached on the way out of the loop *and* on anything thrown out of it, a failed fork
            // included: children already started must not be left to be adopted by init
            self::_terminate($settings);
            self::_restore();

            self::$_supervising = false;
            self::$_count       = 0;
            self::$_slots       = [];
            self::$_pids        = [];
        }

        return $exit;
    }

    /**
     * Whether this process has been asked to stop.
     *
     * True in a child from the moment the master forwards SIGTERM, and in the master from the
     * moment it takes the signal itself. A worker loop reads this to decide whether to take on more
     * work; it is the only thing a callable needs from this class.
     *
     * @return bool
     */
    public static function stopping()
    {
        // The handler runs at the next dispatch point, not the moment the signal lands, so a loop
        // that only ever calls stopping() still notices
        function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();

        return self::$_stop;
    }

    /**
     * Ask this process to stop, without a signal.
     *
     * In a child, ends its loop. In the master, stops the pool the same way SIGTERM would.
     *
     * @return void
     */
    public static function stop()
    {
        self::$_stop = true;
    }

    /**
     * The supervisor loop. Only ever runs in the master.
     *
     * Polls rather than blocking in pcntl_wait(): a wildcard wait reaps *any* child, including ones
     * the host application forked for its own reasons, and waiting on one pid at a time cannot
     * notice a signal. The interval is the worst case restart and stop latency and nothing else --
     * a signal interrupts the sleep, so a stop is usually immediate.
     *
     * @param  callable             $work
     * @param  array<string, mixed> $settings
     * @return int
     * @throws Exception When a fork fails
     */
    private static function _loop(callable $work, array $settings)
    {
        $sleep = (int) ($settings['poll'] * 1000000);

        while ( true )
        {
            pcntl_signal_dispatch();

            if ( self::$_stop )
            {
                return 0;
            }

            if ( !self::_reap($settings) )
            {
                // A slot gave up. Say so with an exit code rather than by throwing: the pool did
                // run, and _terminate() has to take the surviving workers with it either way
                return 1;
            }

            self::_fill($work, $settings);

            // Nothing running and nothing due to start: with restart off, that is the whole pool
            // having finished, and it is the only way out of here that is not a signal
            if ( !self::$_pids && !self::_pending() )
            {
                return 0;
            }

            usleep($sleep);
        }
    }

    /**
     * Collect the children that have ended and decide what each slot does next.
     *
     * @param  array<string, mixed> $settings
     * @return bool  False when a slot has crashed too often and the pool should stop
     */
    private static function _reap(array $settings)
    {
        $healthy = true;

        foreach ( self::_harvest() as $pid => $ended )
        {
            $index = $ended['index'];
            $code  = $ended['code'];
            $slot  = &self::$_slots[$index];

            self::_notify($settings, sprintf(
                'worker %d (pid %d) %s after %.1fs',
                $index,
                $pid,
                $code === null ? 'died on a signal' : 'exited ' . $code,
                $ended['lived']
            ));

            if ( !$settings['restart'] || self::$_stop )
            {
                $slot['retry_at'] = null;

                continue;
            }

            // Lived long enough to have been doing its job: whatever took it down, this is a fresh
            // start and not the continuation of a crash loop
            if ( $ended['lived'] >= $settings['healthy'] )
            {
                $slot['crashes']  = 0;
                $slot['retry_at'] = 0.0;

                continue;
            }

            // A short *clean* exit is rate limited but never fatal: a worker recycling itself on
            // max_jobs under load legitimately looks like this, and stopping the pool over it would
            // turn a busy queue into an outage
            if ( $code === 0 )
            {
                $slot['retry_at'] = microtime(true) + $settings['backoff'];

                continue;
            }

            $slot['crashes']++;

            if ( $slot['crashes'] >= $settings['max_crashes'] )
            {
                $slot['retry_at'] = null;
                $healthy          = false;

                self::_notify($settings, sprintf(
                    'worker %d exited non-zero %d times in a row without staying up %gs; giving up',
                    $index,
                    $slot['crashes'],
                    $settings['healthy']
                ));

                continue;
            }

            $slot['retry_at'] = microtime(true) + $settings['backoff'] * $slot['crashes'];
        }

        unset($slot);

        return $healthy;
    }

    /**
     * Fork a child into every slot that is empty and due.
     *
     * @param  callable             $work
     * @param  array<string, mixed> $settings
     * @return void
     * @throws Exception When a fork fails
     */
    private static function _fill(callable $work, array $settings)
    {
        $now = microtime(true);

        foreach ( self::$_slots as $index => $slot )
        {
            if ( $slot['pid'] !== 0 || $slot['retry_at'] === null || $slot['retry_at'] > $now )
            {
                continue;
            }

            $pid = pcntl_fork();

            if ( $pid === -1 )
            {
                // Thrown, not swallowed: out of processes is not something a retry loop fixes, and
                // supervise()'s finally clause stops the workers already running
                throw new Exception('pool could not fork a worker');
            }

            if ( $pid === 0 )
            {
                // Never returns
                self::_child($work, $index);
            }

            self::$_slots[$index]['pid']     = $pid;
            self::$_slots[$index]['started'] = $now;
            self::$_pids[$pid]               = $index;

            self::_notify($settings, sprintf('worker %d started (pid %d)', $index, $pid));
        }
    }

    /**
     * Whether any slot is empty and waiting to be refilled.
     *
     * @return bool
     */
    private static function _pending()
    {
        foreach ( self::$_slots as $slot )
        {
            if ( $slot['pid'] === 0 && $slot['retry_at'] !== null )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Reap whichever children have ended, without blocking.
     *
     * Waits on each pid by number rather than on -1, so a child of the host application is left
     * where it is.
     *
     * @return array<int, array{index: int, code: int|null, lived: float}>  Keyed by pid; code is
     *         null for a child that died on a signal or that something else reaped first
     */
    private static function _harvest()
    {
        $ended = [];
        $now   = microtime(true);

        foreach ( self::$_pids as $pid => $index )
        {
            $status = 0;
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);

            // 0 means still running. -1 means the pid is gone and carries no status to read
            if ( $reaped === 0 )
            {
                continue;
            }

            $ended[$pid] = [
                'index' => $index,
                // A child killed by a signal has no exit code, and wexitstatus() would report a
                // meaningless 0 -- which would read as a clean recycle rather than a crash
                'code'  => ($reaped > 0 && pcntl_wifexited($status)) ? pcntl_wexitstatus($status) : null,
                'lived' => $now - self::$_slots[$index]['started'],
            ];

            unset(self::$_pids[$pid]);

            self::$_slots[$index]['pid'] = 0;
        }

        return $ended;
    }

    /**
     * Stop every child still running and reap it.
     *
     * SIGTERM first and the same signal to all of them, whatever the master itself was sent: a
     * worker should have one way to be asked to leave. SIGKILL is only for what is left when the
     * grace period is up -- at that point the worker is either wedged or ignoring the signal, and a
     * deadline it can decline is not a deadline.
     *
     * @param  array<string, mixed> $settings
     * @return void
     */
    private static function _terminate(array $settings)
    {
        if ( !self::$_pids )
        {
            return;
        }

        self::_notify($settings, sprintf('stopping %d worker(s)', count(self::$_pids)));

        foreach ( array_keys(self::$_pids) as $pid )
        {
            posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + $settings['grace'];
        $sleep    = (int) ($settings['poll'] * 1000000);

        while ( self::_running() )
        {
            if ( microtime(true) >= $deadline )
            {
                self::_kill();

                self::_notify($settings, 'grace period expired, killed what was left');

                return;
            }

            usleep($sleep);

            self::_harvest();
        }
    }

    /**
     * Whether any child of this pool is still running.
     *
     * @return bool
     */
    private static function _running()
    {
        return self::$_pids !== [];
    }

    /**
     * SIGKILL whatever is left and reap it, so nothing is handed to init as a zombie.
     *
     * @return void
     */
    private static function _kill()
    {
        foreach ( array_keys(self::$_pids) as $pid )
        {
            posix_kill($pid, SIGKILL);

            // Blocking, and safe to be: the process has just been SIGKILLed
            $status = 0;
            pcntl_waitpid($pid, $status);

            unset(self::$_pids[$pid]);
        }
    }

    /**
     * Become a worker: drop what the master owns, run the callable, exit. Never returns.
     *
     * @param  callable $work
     * @param  int      $index
     * @return void
     */
    private static function _child(callable $work, $index)
    {
        // Inherited from the master and none of it a child's business -- the pids above all belong
        // to the master, and reaping one here would take it away from the supervisor
        self::$_pids        = [];
        self::$_slots       = [];
        self::$_saved       = [];
        self::$_stop        = false;
        self::$_supervising = false;

        // Claimed before anything of the caller's runs, because the caller is entitled to ask which
        // worker it is in from its very first line. The child inherited the master's -1 along with
        // everything else, so this is an overwrite rather than a first write
        worker::enter((int) $index, self::$_count);

        // Every entry point notices a fork on its own; doing it at a known point keeps that work
        // out of whichever call happens to come first
        runtime::forked();

        // mt_rand() seeds itself once per process and fork copies the state along with everything
        // else: without this every worker draws the same sequence, and str::random() hands the same
        // string to four processes at once
        mt_srand();

        // The master's handlers were kept for restoring, not for running here
        foreach ( self::_signals() as $signal )
        {
            pcntl_signal($signal, static function ()
            {
                self::$_stop = true;
            });
        }

        $code = 0;

        try
        {
            $result = call_user_func($work, worker::index());
            $code   = is_int($result) ? $result : 0;
        }
        catch ( Throwable $e )
        {
            $code = 1;

            fwrite(STDERR, sprintf(
                "pool worker %d (pid %d): %s in %s:%d\n",
                worker::index(),
                posix_getpid(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        // exit() and not a return: it runs the shutdown functions, and those are what flush what
        // the worker buffered on its way through, the framework's log among them
        exit($code);
    }

    /**
     * Install the master's signal handlers, keeping whatever was there.
     *
     * @return void
     */
    private static function _listen()
    {
        self::$_saved = [];

        foreach ( self::_signals() as $signal )
        {
            // SIG_DFL / SIG_IGN come back as ints, a handler installed by an earlier
            // pcntl_signal() as the callable itself, and both have to be restored as they were.
            // is_callable() is therefore the only question worth asking: everything else is one of
            // the two constants and is passed through unchanged
            $previous = pcntl_signal_get_handler($signal);

            self::$_saved[$signal] = is_callable($previous) ? $previous : (int) $previous;

            pcntl_signal($signal, static function ()
            {
                self::$_stop = true;
            });
        }
    }

    /**
     * Put back the handlers _listen() replaced.
     *
     * supervise() returns rather than ending the process, so a caller that had handlers of its own
     * -- a console command, a test -- gets them back.
     *
     * @return void
     */
    private static function _restore()
    {
        foreach ( self::$_saved as $signal => $handler )
        {
            pcntl_signal($signal, $handler);
        }

        self::$_saved = [];
    }

    /**
     * The signals that stop a pool.
     *
     * A method and not a constant: the SIG* constants come from pcntl, and a class constant holding
     * them would be evaluated even where the extension is missing and supervise() is about to say so.
     *
     * @return array<int, int>
     */
    private static function _signals()
    {
        return [SIGTERM, SIGINT, SIGQUIT];
    }

    /**
     * Merge the caller's options over DEFAULTS.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function _settings(array $options)
    {
        $settings = array_merge(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));

        $settings['grace']       = max(0.0, (float) $settings['grace']);
        $settings['backoff']     = max(0.0, (float) $settings['backoff']);
        $settings['healthy']     = max(0.0, (float) $settings['healthy']);
        $settings['max_crashes'] = max(1, (int) $settings['max_crashes']);
        // Floored rather than trusted: a zero poll turns the supervisor into a busy loop
        $settings['poll']        = min(5.0, max(0.01, (float) $settings['poll']));
        $settings['restart']     = (bool) $settings['restart'];
        $settings['notify']      = is_callable($settings['notify']) ? $settings['notify'] : null;

        return $settings;
    }

    /**
     * Tell the caller's notify callback what just happened.
     *
     * The pool writes nothing on its own: it is used by a console command that has its own opinion
     * about output, and by a test that wants the lines rather than the terminal.
     *
     * @param  array<string, mixed> $settings
     * @param  string               $line
     * @return void
     */
    private static function _notify(array $settings, $line)
    {
        $settings['notify'] === null || call_user_func($settings['notify'], 'pool: ' . $line);
    }
}
