<?php

/**
 * Console command: run the tasks that are due this minute, and list what is scheduled
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\console;

use plato\config;
use plato\log;
use plato\plato;
use plato\runtime;
use Throwable;

/**
 * The scheduler: one crontab entry, and a table of tasks in the application's configuration.
 *
 *     * * * * * cd /srv/app && php vendor/bin/plato schedule:run >> /dev/null 2>&1
 *
 * That line is the whole crontab. Everything else lives in `config/schedule.php`, where it is
 * reviewable, deployable and testable -- which is the point: a crontab is per machine, invisible
 * to the repository, and impossible to keep in step across a fleet.
 *
 *     return [
 *         'enable' => true,
 *         'tasks'  => [
 *             ['expression' => '*&#47;5 * * * *', 'command' => 'queue:work --once'],
 *             ['expression' => '@daily', 'command' => 'report:nightly', 'overlap' => false],
 *             ['expression' => '0 * * * *', 'call' => ['model\stats', 'hourly']],
 *         ],
 *     ];
 *
 * A task names either a `command` -- a console command line, run as **its own process** -- or a
 * `call`, any callable, run in this one. The split is deliberate:
 *
 *   - a command in a subprocess cannot take the scheduler down with it, cannot leak state into the
 *     next task, and lets two tasks due in the same minute run at once. That is worth a process
 *     start for anything that does real work;
 *   - a callable in-process is for the two line jobs where a process start is the expensive part,
 *     and for anything a test needs to observe. It runs to completion before the next task starts.
 *
 * `schedule:run` returns as soon as it has started every due command; it does **not** wait for the
 * subprocesses. A minutely cron entry that waited would pile up.
 *
 * The lifecycle callbacks -- `should_run`, `before`, `after`, `skipped` -- follow the same split, so
 * where they run is decided by which kind of task they are observing. `should_run` is always asked
 * by `schedule:run`, in the scheduler process. `before` / `after` and the overlap `skipped` run
 * wherever the work does: in this process for a callable, and **in the spawned `schedule:exec`
 * child** for a command, which is the process holding that task's lock and watching it finish.
 * A callback therefore has to write somewhere both processes can read -- a table, a cache key, a
 * log -- rather than to a static this process would keep. For the same reason an override handed in
 * with `configure()` reaches callable tasks only: a child reads `config/schedule.php`.
 *
 * `overlap => false` keeps a task that is still running from being joined by the next minute's
 * copy. The guard is **flock on a file under data_path('schedule')**, and not plato\lock, for one
 * reason that decides it: a lock has to be released by the process that finished the work, and
 * lock's token is per process on purpose -- a child cannot release what its parent took, which is
 * the fork safety this framework guarantees elsewhere. An advisory file lock is held by the
 * process holding the descriptor and released by the kernel when that process exits, however it
 * exits. No lifetime to guess, no lock left behind by a task that was killed.
 */
class schedule implements command
{
    /**
     * Settings, null until config() reads them
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Prefix of the plato\runtime keys the lock handles are registered under
     */
    private const LOCK_KEY = 'schedule.lock.';

    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return [
            'schedule:run'  => 'Run the scheduled tasks that are due this minute',
            'schedule:list' => 'List the scheduled tasks and when each of them runs next',
            'schedule:exec' => 'Run one task by name; schedule:run spawns this for command tasks',
        ];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public static function usage(string $name): string
    {
        if ( $name === 'schedule:list' )
        {
            return '';
        }

        if ( $name === 'schedule:exec' )
        {
            return '  --task=NAME            Task to run, whether or not it is due';
        }

        return '  --at=TIMESTAMP         Pretend it is this unix time, for testing a schedule'
            . PHP_EOL . '  --task=NAME            Consider only the task of this name'
            . PHP_EOL . '  --force                Ignore the expression, not the should_run gate;'
            . PHP_EOL . '                         use schedule:exec to run a gated task anyway';
    }

    /**
     * @return array<int, string>
     */
    public static function requires(): array
    {
        return [];
    }

    /**
     * Hand the scheduler its tasks instead of letting it read config/schedule.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as config/schedule.php
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
     * @param string $name
     *
     * @return int
     */
    public static function handle(string $name): int
    {
        if ( $name === 'schedule:list' )
        {
            return self::_list();
        }

        if ( $name === 'schedule:exec' )
        {
            return self::_exec();
        }

        return self::_run();
    }

    /**
     * Start everything due at this minute.
     *
     * @return int
     */
    private static function _run(): int
    {
        $config = (array) self::config();

        if ( empty($config['enable']) )
        {
            console::warn('The scheduler is switched off in config/schedule.php');

            return console::OK;
        }

        $at    = self::_at();
        $only  = console::option('task');
        $force = (bool) console::option('force', false);
        $ran   = 0;

        foreach ( self::tasks() as $task )
        {
            if ( is_string($only) && $only !== '' && $task['name'] !== $only )
            {
                continue;
            }

            if ( !$force && !cron::due($task['expression'], $at) )
            {
                continue;
            }

            if ( !self::_should_run($task) )
            {
                self::_skipped($task, 'filtered');

                continue;
            }

            $ran += self::_start($task) ? 1 : 0;
        }

        console::line(sprintf('%d task%s started', $ran, $ran === 1 ? '' : 's'));

        return console::OK;
    }

    /**
     * Run one named task in this process: what schedule:run spawns for a command task.
     *
     * @return int
     */
    private static function _exec(): int
    {
        $name = console::option('task');

        if ( !is_string($name) || $name === '' )
        {
            console::fail('schedule:exec needs --task=NAME');

            return console::FAILURE;
        }

        foreach ( self::tasks() as $task )
        {
            if ( $task['name'] !== $name )
            {
                continue;
            }

            // The lock is taken here and not in schedule:run, because this is the process that
            // does the work and therefore the one whose exit should release it
            if ( !$task['overlap'] && !self::_take_lock($task['name']) )
            {
                console::warn(sprintf('%s is still running, skipped', $task['name']));
                self::_skipped($task, 'overlap');

                return console::OK;
            }

            return self::_do($task) ? console::OK : console::FAILURE;
        }

        console::fail('no scheduled task called ' . $name);

        return console::FAILURE;
    }

    /**
     * Print the table, with the next due time of each entry.
     *
     * @return int
     */
    private static function _list(): int
    {
        $tasks = self::tasks();

        if ( $tasks === [] )
        {
            console::warn('Nothing is scheduled; add tasks to config/schedule.php');

            return console::OK;
        }

        $at = self::_at();

        console::line(sprintf('%-24s %-16s %-18s %-9s %s', 'NAME', 'EXPRESSION', 'NEXT', 'OVERLAP', 'RUNS'));

        foreach ( $tasks as $task )
        {
            $next = cron::next($task['expression'], $at);

            console::line(sprintf(
                '%-24s %-16s %-18s %-9s %s',
                $task['name'],
                $task['expression'],
                $next === null ? 'never' : date('Y-m-d H:i', $next),
                $task['overlap'] ? 'yes' : 'no',
                $task['command'] !== null ? $task['command'] : 'callable'
            ));
        }

        return console::OK;
    }

    /**
     * The configured tasks, each one filled in and checked.
     *
     * Public because schedule:list, the tests and an application's own health check all want the
     * same normalised view rather than the raw configuration.
     *
     * @return array<int, array{name: string, expression: string, command: string|null,
     *                          call: callable|null, overlap: bool}>
     */
    public static function tasks(): array
    {
        $tasks = [];

        foreach ( self::inspect() as $task )
        {
            if ( $task['error'] !== null )
            {
                console::fail($task['error']);

                continue;
            }

            unset($task['error']);
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * Inspect every configured task without writing output or running lifecycle callbacks.
     *
     * Invalid entries stay in the result with an error instead of disappearing. This is the
     * read-only view for health checks and management screens; tasks() remains the executable
     * view and reports then drops those entries.
     *
     * @return array<int, array{name: string, expression: string, command: string|null,
     *                          call: callable|null, overlap: bool, error: string|null}>
     */
    public static function inspect(): array
    {
        $tasks = [];

        foreach ( (array) (self::config('tasks') ?? []) as $index => $raw )
        {
            $task       = is_array($raw) ? $raw : [];
            $command    = isset($task['command']) ? trim((string) $task['command']) : '';
            $call       = $task['call'] ?? null;
            $expression = trim((string) ($task['expression'] ?? '@always'));
            $error      = null;

            if ( !is_array($raw) )
            {
                $error = sprintf('schedule task #%s is not an array', $index);
            }
            elseif ( $command === '' && !is_callable($call) )
            {
                $error = sprintf('schedule task #%s has neither a command nor a callable', $index);
            }
            else
            {
                try
                {
                    cron::parse($expression);
                }
                catch ( Throwable $e )
                {
                    $error = sprintf('schedule task #%s has an invalid expression: %s', $index, $e->getMessage());
                }
            }

            $tasks[] = [
                'name'       => (string) ($task['name'] ?? ($command !== '' ? $command : 'task#' . $index)),
                'expression' => $expression,
                'command'    => $command === '' ? null : $command,
                'call'       => is_callable($call) ? $call : null,
                'overlap'    => ($task['overlap'] ?? true) !== false,
                'error'      => $error,
            ];
        }

        return $tasks;
    }

    /**
     * Start one task: in this process when it is a callable, in a new one when it is a command.
     *
     * @param array{name: string, expression: string, command: string|null, call: callable|null,
     *              overlap: bool} $task
     *
     * @return bool  Whether the task was started
     */
    private static function _start(array $task): bool
    {
        if ( $task['call'] !== null )
        {
            // In-process, so this process takes the lock and holds it for the length of the call
            if ( !$task['overlap'] && !self::_take_lock($task['name']) )
            {
                console::warn(sprintf('%s is still running, skipped', $task['name']));
                self::_skipped($task, 'overlap');

                return false;
            }

            console::line('Running ' . $task['name']);

            try
            {
                self::_do($task);
            }
            finally
            {
                self::_release_lock($task['name']);
            }

            // Started, which is what the count means: a task that threw still ran, and _do() has
            // already said so on stderr
            return true;
        }

        console::line('Starting ' . $task['name']);

        // The child takes its own lock, so a task whose previous run is still going costs one
        // process start and nothing else
        self::_spawn($task['name']);

        return true;
    }

    /**
     * Do the work of a task, in this process.
     *
     * @param array{name: string, expression: string, command: string|null, call: callable|null,
     *              overlap: bool} $task
     *
     * @return bool
     */
    private static function _do(array $task): bool
    {
        $started = microtime(true);
        self::_notify('before', [$task]);

        $ok        = false;
        $exit_code = 0;
        $error     = null;

        if ( $task['call'] !== null )
        {
            try
            {
                call_user_func($task['call']);
                $ok = true;
            }
            catch ( Throwable $e )
            {
                // One task throwing must not stop the ones after it
                console::fail(sprintf('%s failed: %s', $task['name'], $e->getMessage()));
                log::error($e->getMessage(), 'schedule:' . $task['name']);
                $exit_code = 1;
                $error     = $e->getMessage();
            }
        }
        else
        {
            $line = self::_binary() . ' ' . (string) $task['command'];

            // Synchronous: this process holds the lock, so it has to be here until the work is done
            passthru($line, $exit_code);
            $ok = $exit_code === 0;

            if ( !$ok )
            {
                $error = sprintf('%s exited with %d', $task['name'], $exit_code);
                console::fail($error);
            }
        }

        $finished = microtime(true);

        self::_notify('after', [$task, [
            'ok'          => $ok,
            'started_at'  => $started,
            'finished_at' => $finished,
            'duration'    => $finished - $started,
            'exit_code'   => $exit_code,
            'error'       => $error,
        ]]);

        return $ok;
    }

    /**
     * Ask the application whether a due task should run automatically.
     *
     * schedule:exec deliberately bypasses this gate, so an operator can still run a paused task
     * by name. A broken gate fails closed for that task and is reported as an observer failure.
     *
     * @param array{name: string, expression: string, command: string|null, call: callable|null,
     *              overlap: bool} $task
     */
    private static function _should_run(array $task): bool
    {
        $hook = self::config('should_run');

        if ( !is_callable($hook) )
        {
            return true;
        }

        try
        {
            return call_user_func($hook, $task) !== false;
        }
        catch ( Throwable $e )
        {
            self::_hook_failed('should_run', $task, $e);

            return false;
        }
    }

    /**
     * Tell the application that a due task did not start.
     *
     * @param array{name: string, expression: string, command: string|null, call: callable|null,
     *              overlap: bool} $task
     * @param string $reason Stable framework reason: filtered or overlap
     */
    private static function _skipped(array $task, string $reason): void
    {
        self::_notify('skipped', [$task, $reason]);
    }

    /**
     * Run an observer callback without letting observability change the task result.
     *
     * @param string            $name
     * @param array<int, mixed> $arguments
     */
    private static function _notify(string $name, array $arguments): void
    {
        $hook = self::config($name);

        if ( !is_callable($hook) )
        {
            return;
        }

        try
        {
            call_user_func_array($hook, $arguments);
        }
        catch ( Throwable $e )
        {
            /** @var array{name: string} $task */
            $task = $arguments[0];
            self::_hook_failed($name, $task, $e);
        }
    }

    /**
     * Report a lifecycle callback failure consistently.
     *
     * @param string $name
     * @param array{name: string} $task
     * @param Throwable $e
     */
    private static function _hook_failed(string $name, array $task, Throwable $e): void
    {
        $message = sprintf('%s hook failed for %s: %s', $name, $task['name'], $e->getMessage());

        console::fail($message);
        log::error($message, 'schedule:' . $task['name']);
    }

    /**
     * Start `plato schedule:exec --task=NAME` in the background.
     *
     * @param string $name
     *
     * @return void
     */
    private static function _spawn(string $name): void
    {
        $line = self::_binary() . ' schedule:exec --task=' . escapeshellarg($name);

        exec('(' . $line . ') > /dev/null 2>&1 &');
    }

    /**
     * `php bin/plato`, quoted, whichever layout the package is installed in.
     *
     * @return string
     */
    private static function _binary(): string
    {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(plato::framework_path('bin/plato'));
    }

    /**
     * Take the overlap lock of a task, without waiting for it.
     *
     * The handle is held for as long as the work runs -- closing it releases the lock, and that is
     * exactly what must not happen early -- and it is held in plato\runtime rather than in a static
     * property of this class. A descriptor inherited through a fork shares one open file
     * description with its parent, and flock() cannot serialise two processes that share one: every
     * LOCK_EX among them is granted at once, which is the opposite of an overlap guard. Registering
     * it means a child does not believe it holds a lock its parent took.
     *
     * @param string $name Task name
     *
     * @return bool  False when another process is holding it
     */
    private static function _take_lock(string $name): bool
    {
        if ( runtime::has(self::LOCK_KEY . $name) )
        {
            return true;
        }

        $dir = plato::data_path('schedule');

        if ( !is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir) )
        {
            console::fail('cannot create ' . $dir . ', running ' . $name . ' without an overlap guard');

            return true;
        }

        $file   = $dir . DIRECTORY_SEPARATOR . md5($name) . '.lock';
        $handle = @fopen($file, 'c');

        if ( !is_resource($handle) )
        {
            console::fail('cannot open ' . $file . ', running ' . $name . ' without an overlap guard');

            return true;
        }

        if ( !flock($handle, LOCK_EX | LOCK_NB) )
        {
            fclose($handle);

            return false;
        }

        // Whose process is holding it, for whoever goes looking at the directory
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        runtime::share(
            self::LOCK_KEY . $name,
            static fn () => $handle,
            static function ($open): void
            {
                flock($open, LOCK_UN);
                fclose($open);
            }
        );

        return true;
    }

    /**
     * Release a lock this process took.
     *
     * Only the in-process path calls it: a spawned task releases by exiting, which is the whole
     * reason the guard is a file lock.
     *
     * @param string $name Task name
     *
     * @return void
     */
    private static function _release_lock(string $name): void
    {
        runtime::forget(self::LOCK_KEY . $name);
    }

    /**
     * The minute to evaluate the expressions against.
     *
     * @return int
     */
    private static function _at(): int
    {
        $at = console::option('at');

        return is_string($at) && $at !== '' ? (int) $at : time();
    }

    /**
     * The configuration, read on the first call that needs it.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('schedule')->get();
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }
}
