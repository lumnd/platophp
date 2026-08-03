<?php

/**
 * Process scoped resources: the one place that knows what a fork invalidates
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

/**
 * Registry of everything that belongs to *this* process and to no other.
 *
 * A framework built on static state has two boundaries to respect, and they are not the same one:
 *
 *   process boundary   pcntl_fork() hands the child a copy of every static property, the open
 *                      sockets and file descriptors among them. Two processes writing into one
 *                      socket interleave; two processes sharing one open file description cannot
 *                      be serialised by flock(), because every LOCK_EX among them is granted at
 *                      once. This class owns that boundary.
 *   request boundary   a resident entry point (Workerman, RoadRunner, FrankenPHP worker mode)
 *                      serves many requests from one process, so request state has to be cleared
 *                      between them. That boundary belongs to the capture() methods called by
 *                      plato::registry(), not here.
 *
 * Anything holding a connection, a handle or a token registers it here instead of in a static
 * property of its own:
 *
 *     $handler = runtime::share('redis.cache', function ()
 *     {
 *         return new \Redis();
 *     });
 *
 * share() notices that the pid changed, drops the whole map, and calls the factory again -- so the
 * child connects for itself rather than writing into the socket it inherited. State that is not a
 * resource but is still invalidated by a fork (a lock token identifying its holder, say) registers
 * an on_fork() listener instead.
 *
 * **Coroutines are out of scope, deliberately.** Everything here is keyed by process id, and a
 * coroutine scheduler runs many requests inside one process under one pid. A framework carrying
 * static state cannot serve concurrent coroutines whatever this class does, so PlatoPHP supports
 * php-fpm, forked CLI workers and resident single-request-at-a-time workers, and says so out loud
 * rather than half working under Swoole's coroutine mode.
 */
class runtime
{
    /**
     * Resources of this process, key => ['value' => mixed, 'closer' => callable|null].
     *
     * @var array<string, array{value: mixed, closer: callable|null}>
     */
    private static $_shared = [];

    /**
     * Resources built before the last fork, kept alive on purpose. See _fork().
     *
     * @var array<int, mixed>
     */
    private static $_abandoned = [];

    /**
     * Process the map belongs to, 0 until the first call.
     *
     * @var int
     */
    private static $_pid = 0;

    /**
     * Generation of the map, incremented by every fork this process notices.
     *
     * @var int
     */
    private static $_epoch = 0;

    /**
     * Listeners run when a fork is noticed, handle => callable.
     *
     * @var array<int, callable>
     */
    private static $_on_fork = [];

    /**
     * Handle of the last registered listener.
     *
     * @var int
     */
    private static $_fh = 0;

    /**
     * Work deferred until the response has been flushed, see shutdown_function().
     *
     * @var array<int, array{func: callable|string, params: array<mixed>}>
     */
    private static $_deferred = [];

    /**
     * Id of the process the map currently belongs to.
     *
     * @param  bool $check Whether to look for a fork first; false answers from the last check
     * @return int
     */
    public static function pid($check = true)
    {
        $check && self::_check();

        return self::$_pid;
    }

    /**
     * Generation of the map: 0 in the process that started it, one more after every fork.
     *
     * An object that outlives a fork -- one a caller kept a reference to -- compares the epoch it
     * was built in against this to find out whether its handle is still its own.
     *
     * @return int
     */
    public static function epoch()
    {
        self::_check();

        return self::$_epoch;
    }

    /**
     * Tell the runtime a fork just happened, from inside the child.
     *
     * Only ever an optimisation: every other entry point notices a fork on its own. Calling it
     * right after pcntl_fork() moves the work to a known point instead of leaving it to whichever
     * call happens to come first.
     *
     * @return bool Whether this call was the one that noticed
     */
    public static function forked()
    {
        return self::_check();
    }

    /**
     * The resource registered under $key, built by $factory on first use.
     *
     * The factory runs again after a fork, and after forget() or flush(). It must not ask for its
     * own key: nothing is registered until it returns, so that would build the resource twice.
     *
     * @param  string        $key     Registry key, conventionally 'module.name'
     * @param  callable      $factory Builds the resource, receives no arguments
     * @param  callable|null $closer  Releases the resource, receives it as its only argument.
     *                                Run by forget() and flush(), never on a fork -- see _fork()
     * @return mixed
     */
    public static function share($key, callable $factory, ?callable $closer = null)
    {
        self::_check();

        if ( !array_key_exists($key, self::$_shared) )
        {
            // Built before it is registered, so a factory that throws leaves nothing behind and
            // the next call tries again
            $value = $factory();

            self::$_shared[$key] = ['value' => $value, 'closer' => $closer];
        }

        return self::$_shared[$key]['value'];
    }

    /**
     * Whether $key holds a resource in this process.
     *
     * @param  string $key
     * @return bool
     */
    public static function has($key)
    {
        self::_check();

        return array_key_exists($key, self::$_shared);
    }

    /**
     * The resource registered under $key without building it.
     *
     * For code that has to tell "not built yet" from "built": a teardown path should not open a
     * connection just to close it.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        self::_check();

        return array_key_exists($key, self::$_shared) ? self::$_shared[$key]['value'] : $default;
    }

    /**
     * Release one resource and forget it.
     *
     * @param  string $key
     * @return bool   False when nothing was registered under $key
     */
    public static function forget($key)
    {
        self::_check();

        if ( !array_key_exists($key, self::$_shared) )
        {
            return false;
        }

        $entry = self::$_shared[$key];
        // Unset first: a closer that reaches back into the registry -- an object whose destructor
        // calls forget() again -- has to find the key already gone
        unset(self::$_shared[$key]);

        self::_close($entry);

        return true;
    }

    /**
     * Release every resource of this process.
     *
     * Two callers: a test, between cases, and plato\pool, in the parent right before it
     * forks -- a child that inherits nothing has nothing to get wrong.
     *
     * @return void
     */
    public static function flush()
    {
        self::_check();

        $shared        = self::$_shared;
        self::$_shared = [];

        // Reverse order, so something registered on top of another resource is released first
        foreach ( array_reverse($shared, true) as $entry )
        {
            self::_close($entry);
        }
    }

    /**
     * Run $listener whenever a fork is noticed.
     *
     * For state a fork invalidates that is not a resource, and so has nothing to register with
     * share(): support\lock clears the tokens of the locks its process holds this way, because a
     * child inheriting them would be able to release a lock its parent is still holding.
     *
     * @param  callable $listener Receives no arguments
     * @return int      Handle, pass it to off_fork() to unregister
     */
    public static function on_fork(callable $listener)
    {
        self::_check();

        self::$_on_fork[++self::$_fh] = $listener;

        return self::$_fh;
    }

    /**
     * Unregister a fork listener.
     *
     * @param  int  $handle Value returned by on_fork()
     * @return bool
     */
    public static function off_fork($handle)
    {
        if ( !isset(self::$_on_fork[$handle]) )
        {
            return false;
        }

        unset(self::$_on_fork[$handle]);

        return true;
    }

    /**
     * Queue work to run after the response has been flushed to the client.
     *
     *     runtime::shutdown_function(['plato\log', 'error'], ['message']);
     *
     * Callbacks pile up until this is called with $end, at which point fastcgi_finish_request()
     * closes the connection and the queue drains with the client already gone. Under CLI there
     * is nobody to release, so the callback runs immediately instead of being queued.
     *
     * The queue is process scoped like everything else here: a fork drops what the parent had
     * pending rather than letting the child drain it a second time.
     *
     * @param  callable|string|null $func   Callback, as a [class, method] pair or a function name.
     *                                      Null only makes sense together with $end
     * @param  array<mixed>         $params Arguments passed to the callback
     * @param  bool                 $end    Whether to flush the response and drain the queue now
     *
     * @return mixed  The callback return value under CLI, null otherwise
     */
    public static function shutdown_function($func, $params = [], $end = false)
    {
        if ( PHP_SAPI === 'cli' )
        {
            return $func ? call_user_func_array($func, $params) : null;
        }

        self::_check();

        if ( $func )
        {
            self::$_deferred[] = ['func' => $func, 'params' => $params];
        }

        if ( $end )
        {
            function_exists('fastcgi_finish_request') && fastcgi_finish_request();

            // Taken before draining: a callback queueing more work must not extend this drain
            $queue = self::$_deferred;
            self::$_deferred = [];

            foreach ( $queue as $v )
            {
                call_user_func_array($v['func'], $v['params']);
            }
        }

        return null;
    }

    /**
     * Keys currently registered, for debugging and for tests.
     *
     * @return array<int, string>
     */
    public static function keys()
    {
        self::_check();

        return array_keys(self::$_shared);
    }

    /**
     * How many resources this process inherited and abandoned, see _fork().
     *
     * Zero in a worker started by plato\pool, which releases everything before it
     * forks. Anything above that means something forked without saying so and is holding its
     * parent's descriptors open for the rest of its life.
     *
     * @return int
     */
    public static function abandoned()
    {
        self::_check();

        return count(self::$_abandoned);
    }

    /**
     * Look for a fork and handle it.
     *
     * @return bool Whether this call was the one that noticed
     */
    private static function _check()
    {
        $pid = (int) getmypid();

        if ( self::$_pid === $pid )
        {
            return false;
        }

        // First call of the process that started the registry, not a fork: there is nothing
        // inherited to drop and no listener has been registered yet
        if ( self::$_pid === 0 )
        {
            self::$_pid = $pid;

            return false;
        }

        self::_fork($pid);

        return true;
    }

    /**
     * Adopt the identity of the forked child and drop what it inherited.
     *
     * Inherited resources are **abandoned, not closed**. Closing one means running the client's
     * teardown against a descriptor the parent is still using, and some clients say goodbye to the
     * server from there -- mysqli sends COM_QUIT, which ends the session the parent is in the
     * middle of. Dropping the last reference has the same effect, because that is what runs the
     * destructor, so they are held here instead: a worker keeps a handful of dead objects alive
     * for its own lifetime, which costs nothing next to killing its parent's connection.
     *
     * That is a backstop, not the plan. The parent releasing everything before it forks is the
     * plan, and plato\pool does exactly that.
     *
     * @param  int  $pid
     * @return void
     */
    private static function _fork($pid)
    {
        // Set before the listeners run, so a listener calling back in does not recurse
        self::$_pid = $pid;
        self::$_epoch++;

        foreach ( self::$_shared as $entry )
        {
            self::$_abandoned[] = $entry['value'];
        }

        self::$_shared = [];

        // The parent's deferred work belongs to the parent's response. A child that inherited the
        // queue and drained it would run every callback twice.
        self::$_deferred = [];

        foreach ( self::$_on_fork as $listener )
        {
            call_user_func($listener);
        }
    }

    /**
     * Run an entry's closer.
     *
     * A closer that throws is swallowed on purpose: flush() has to get through the whole map, and
     * "this connection was already gone" is the usual reason a teardown raises.
     *
     * @param  array{value: mixed, closer: callable|null} $entry
     * @return void
     */
    private static function _close(array $entry)
    {
        if ( $entry['closer'] === null )
        {
            return;
        }

        try
        {
            call_user_func($entry['closer'], $entry['value']);
        }
        catch ( \Throwable )
        {
            // Nothing to do about it, and nowhere to report it that is not itself a resource
        }
    }
}
