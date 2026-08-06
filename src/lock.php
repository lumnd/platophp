<?php

/**
 * Distributed lock backed by redis
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use plato\cache\redis;

/**
 * Distributed lock backed by redis.
 *
 * The lock is a key written only if it does not exist yet, carrying a lifetime so a process
 * dying while holding it does not block everyone else forever, and a token so that releasing it
 * can tell the holder apart from a latecomer.
 *
 *     // Mutex: give up as soon as the lock is taken, and release whatever the job does
 *     lock::guard('test', fn () => do_job());
 *
 *     // Spin lock: retry until the lock is free, for at most 3 seconds
 *     lock::guard('test', fn () => do_job(), 3);
 *
 *     // The same by hand, when the job cannot be a closure
 *     if ( lock::lock('test') )
 *     {
 *         do_job();
 *         lock::unlock('test');
 *     }
 *
 * The token is remembered per process, so unlock() and expire() only work from the process that
 * took the lock, and only while it still holds it. A job that runs past $expire loses the lock
 * to the next caller and its unlock() then does nothing, instead of dropping the lock the other
 * process is holding -- but it is running unguarded from the moment the lock expired, so keep
 * $expire above the longest run of the guarded job rather than relying on this.
 *
 * That promise needs the check and the release to be one operation. Reading the token and then
 * deleting the key are two, and in between them the lock can expire and be taken by somebody
 * else, whose lock the delete would drop; unlock() and expire() go through WATCH / MULTI / EXEC
 * instead, which fails rather than acts when the key changed after it was read.
 *
 * **Per process, not per acquisition.** Two places in one process taking the same name share one
 * token: the second lock() fails, as it should, and an unlock() from either of them releases what
 * the first one took. What guards a lock from another process does not guard it from the rest of
 * its own, so prefer guard(), which releases through the token its own call took and cannot be
 * talked out of it by anything the job does. owns() answers whether this process already holds a
 * name, which is the cheap way not to wait out a timeout against oneself.
 *
 * The tokens are cleared when the process forks (see the runtime listener in
 * _ensure_fork_listener()). A child inherits every static property its parent had, the tokens
 * among them, and would otherwise be able to release a lock the parent is still holding.
 *
 * A lock left behind by a process that died is released by its lifetime, not by unlock(); there
 * is deliberately no way to force one open, since that is the same command that broke the lock
 * before it carried a token: del(lock::config('prefix') . 'name') if an operator really has to.
 *
 * The connection comes from the `lock` section of config/config.php, not from the cache facade:
 * locks want a server that never evicts a key under memory pressure, and a cache server with an
 * `allkeys-*` policy is free to drop the lock two processes are relying on. `connection` names
 * the logical redis connection, `server` overrides its settings, and bind() replaces the whole
 * client, which is what a test does instead of reaching a real server.
 */
class lock
{
    /**
     * Settings, null until config() reads the `lock` section of config/config.php
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Defaults, replaced key by key from the configuration
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        // Logical redis connection the locks live on. A name of its own gets a socket of its own,
        // which is what keeps a lock from waiting behind somebody else's blocking command
        'connection'       => 'redis',
        // Settings of that connection, shaped like the `redis.server` section of config/cache.php.
        // Empty leaves them to the connection itself, which puts the locks on the cache server;
        // name them to move the locks to a server of their own, and spell out `prefix` when doing
        // so, since only the cache settings carry one by default
        'server'           => [],
        // Key prefix of every lock
        'prefix'           => 'Lock:',
        // Lifetime of a lock, for callers that do not name one
        'expire'           => 15,
        // Pause between two attempts while waiting for a lock, in microseconds
        'wait_interval_us' => 100000,
    ];

    /**
     * Connection handed in by bind(), null to resolve one from the settings
     *
     * @var redis|null
     */
    private static $_client = null;

    /**
     * Tokens of the locks this process holds, keyed by redis key
     *
     * @var array<string, string>
     */
    private static $_tokens = [];

    /**
     * Whether the fork listener has been registered
     *
     * @var bool
     */
    private static $_listening = false;

    /**
     * The effective settings, read from the `lock` section on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('lock', []) + self::DEFAULTS;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand the locks their settings instead of letting them read config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes.
     *
     * @param array<string, mixed> $config Same shape as the `lock` section
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Use $client for every command instead of resolving a connection from the settings.
     *
     * Replaces the client as a whole, null puts the settings back in charge. The tokens go with
     * it: they name keys on the connection that is being replaced, and mean nothing on the next
     * one.
     *
     * @param redis|null $client
     *
     * @return void
     */
    public static function bind(?redis $client): void
    {
        self::$_client = $client;
        self::$_tokens = [];
    }

    /**
     * Drop the overrides, the bound client, and the tokens.
     *
     * The tokens go because a caller resetting the class is starting over, and a token whose
     * connection is about to change cannot release anything. **The locks themselves stay**: they
     * are released by their lifetime after this, not by unlock(), so do not reset while holding
     * one that matters.
     *
     * @return void
     */
    public static function reset(): void
    {
        // Not $_listening: the listener stays registered in the runtime, and forgetting that we
        // registered it would add a second one on the next call
        self::$_client = null;
        self::$_tokens = [];
        self::$_config = null;
    }

    /**
     * Make sure the fork listener is registered, and that a fork that already happened has been
     * noticed.
     *
     * Not a config(): that idiom runs its body once and returns early ever after, and this has to
     * do work on every call. A child inherits $_listening as true, so an early return would leave
     * the fork unnoticed until something else touches the registry -- which is after unlock() has
     * already read the token it inherited from its parent. pid() is the cheapest call that runs
     * the check and, with it, the listener below.
     *
     * @return void
     */
    private static function _ensure_fork_listener()
    {
        runtime::pid();

        if ( self::$_listening )
        {
            return;
        }

        self::$_listening = true;

        runtime::on_fork(static function ()
        {
            // The locks belong to the process that took them, and this is no longer that process
            self::$_tokens = [];
        });
    }

    /**
     * Acquire a lock.
     *
     * @param string   $name             Lock name
     * @param int      $timeout          How long to keep retrying, in seconds; 0 returns right
     *                                   away when the lock is taken
     * @param int|null $expire           Lifetime of the lock in seconds, after which it is
     *                                   released whether or not the holder is done; null takes
     *                                   the `expire` setting
     * @param int|null $wait_interval_us Pause between two attempts, in microseconds; null takes
     *                                   the `wait_interval_us` setting
     *
     * @return bool
     */
    public static function lock(
        string $name,
        int $timeout = 0,
        ?int $expire = null,
        ?int $wait_interval_us = null
    ): bool
    {
        if ( $name === '' )
        {
            return false;
        }

        return self::_acquire(self::_key($name), $timeout, $expire, $wait_interval_us) !== null;
    }

    /**
     * Take the lock, run $work, and release it again.
     *
     * The release goes through the token this call took rather than through the token map that
     * lock() and unlock() share, so nothing $work does -- releasing the same name, taking it
     * again, throwing -- can make it release a lock somebody else took or skip its own. That is
     * the difference from lock() / unlock() worth having: the map is per process, and a second
     * place in the same process holding the same name is exactly what it cannot tell apart.
     *
     * **The lifetime is not extended while $work runs**, because there is nothing to run a
     * heartbeat on between two statements of somebody else's job. Keep $expire above the longest
     * run of $work, or call expire() from inside it.
     *
     * @param string   $name    Lock name
     * @param callable $work    Called with no arguments, once, with the lock held
     * @param int      $timeout How long to keep retrying, in seconds; 0 gives up right away
     * @param int|null $expire  Lifetime of the lock in seconds; null takes the `expire` setting
     *
     * @return mixed  What $work returned, or false when the lock could not be taken. A $work
     *                answering false of its own is indistinguishable from that, so say it another
     *                way when the difference matters
     */
    public static function guard(string $name, callable $work, int $timeout = 0, ?int $expire = null)
    {
        if ( $name === '' )
        {
            return false;
        }

        $redis_key = self::_key($name);
        $token     = self::_acquire($redis_key, $timeout, $expire, null);

        if ( $token === null )
        {
            return false;
        }

        try
        {
            return $work();
        }
        finally
        {
            self::_release($redis_key, $token);
        }
    }

    /**
     * Release a lock held by this process.
     *
     * @param string $name Lock name
     *
     * @return bool  False when this process does not hold the lock any more
     */
    public static function unlock(string $name): bool
    {
        self::_ensure_fork_listener();

        $redis_key = self::_key($name);
        $token     = self::$_tokens[$redis_key] ?? null;

        if ( $token === null )
        {
            return false;
        }

        return self::_release($redis_key, $token);
    }

    /**
     * Whether this process took the lock and has not released it.
     *
     * Answered from the token this process kept, without asking the server, so it says the lock
     * was taken here and not given back -- not that it is still held. A lifetime that ran out in
     * the meantime is invisible to it, the same way it is invisible to unlock(); ttl() is the one
     * that asks the server.
     *
     * Cheap on purpose: it is what keeps a caller from waiting out a whole timeout on a lock its
     * own process is holding, which no timeout can resolve.
     *
     * @param string $name Lock name
     *
     * @return bool
     */
    public static function owns(string $name): bool
    {
        self::_ensure_fork_listener();

        return isset(self::$_tokens[self::_key($name)]);
    }

    /**
     * Lifetime left on a lock, whoever holds it.
     *
     * @param string $name Lock name
     *
     * @return int  Seconds left, -1 when the lock carries no lifetime at all, -2 when nobody
     *              holds it
     */
    public static function ttl(string $name): int
    {
        self::_ensure_fork_listener();

        return (int) self::_client()->ttl(self::_key($name));
    }

    /**
     * Extend the lifetime of a lock held by this process.
     *
     * @param string   $name   Lock name
     * @param int|null $expire Lifetime in seconds counted from now, at least 1; null takes the
     *                         `expire` setting
     *
     * @return bool
     */
    public static function expire(string $name, ?int $expire = null): bool
    {
        self::_ensure_fork_listener();

        $redis_key = self::_key($name);
        $token     = self::$_tokens[$redis_key] ?? null;

        if ( $token === null )
        {
            return false;
        }

        $expire = max($expire ?? (int) self::config('expire'), 1);

        $extended = self::_guarded($redis_key, $token, static function ($tx) use ($redis_key, $expire)
        {
            return $tx->expire($redis_key, $expire);
        });

        if ( !$extended )
        {
            // The only way to fail is that the key is not what this process wrote any more, so the
            // token has nothing left to release; keeping it would only mislead owns() and unlock()
            unset(self::$_tokens[$redis_key]);
        }

        return $extended;
    }

    /**
     * Whether the lock is held, by this process or any other one.
     *
     * @param string $name Lock name
     *
     * @return bool
     */
    public static function is_locking(string $name): bool
    {
        self::_ensure_fork_listener();

        return self::_client()->has(self::_key($name));
    }

    /**
     * Write the key if nobody holds it, retrying until $timeout runs out.
     *
     * @param string   $redis_key        Prefixed key
     * @param int      $timeout          How long to keep retrying, in seconds
     * @param int|null $expire           Lifetime in seconds, null takes the `expire` setting
     * @param int|null $wait_interval_us Pause between two attempts, null takes the setting
     *
     * @return string|null  The token this call wrote, null when the lock stayed taken
     */
    private static function _acquire(
        string $redis_key,
        int $timeout,
        ?int $expire,
        ?int $wait_interval_us
    ): ?string
    {
        self::_ensure_fork_listener();

        $expire           = max($expire ?? (int) self::config('expire'), 1);
        $wait_interval_us = max($wait_interval_us ?? (int) self::config('wait_interval_us'), 0);
        $timeout_at       = microtime(true) + $timeout;
        $token            = self::_token();

        while ( true )
        {
            // Write and expire in one command: SETNX followed by EXPIRE leaves a lock that
            // never expires when the process dies between the two
            if ( self::_client()->set_nx($redis_key, $token, $expire) )
            {
                self::$_tokens[$redis_key] = $token;

                return $token;
            }

            if ( $timeout <= 0 || $timeout_at < microtime(true) )
            {
                return null;
            }

            usleep($wait_interval_us);
        }
    }

    /**
     * Delete the key, but only while $token is still the one on it.
     *
     * @param string $redis_key Prefixed key
     * @param string $token     Token the caller took
     *
     * @return bool  False when the lock is not this call's any more
     */
    private static function _release(string $redis_key, string $token): bool
    {
        // Dropped before the server is asked: the caller is done with the lock either way, and a
        // token it no longer owns is only a way to delete somebody else's key later. Only when it
        // is still this token, though -- a later acquisition of the same name wrote its own, and
        // that one belongs to whoever is still running with it
        if ( (self::$_tokens[$redis_key] ?? null) === $token )
        {
            unset(self::$_tokens[$redis_key]);
        }

        return self::_guarded($redis_key, $token, static function ($tx) use ($redis_key)
        {
            return $tx->del($redis_key);
        });
    }

    /**
     * The connection the locks live on.
     *
     * The resolved instance is deliberately not kept in a static of this class: redis::instance()
     * registers it with the runtime, which is what drops it when the process forks. A copy held
     * here would survive that and hand a child its parent's socket.
     *
     * @return redis
     */
    private static function _client(): redis
    {
        if ( self::$_client !== null )
        {
            return self::$_client;
        }

        $server = (array) self::config('server');

        return redis::instance((string) self::config('connection'), $server === [] ? null : $server);
    }

    /**
     * Redis key of a lock.
     *
     * @param string $name Lock name
     *
     * @return string
     */
    private static function _key(string $name): string
    {
        return (string) self::config('prefix') . $name;
    }

    /**
     * Run a command against the lock, but only while this process still holds it.
     *
     * WATCH the key, read it, and queue the command in a transaction: EXEC does nothing and
     * answers false when anybody wrote the key after the WATCH, which is precisely the case this
     * guards against -- the lock expiring and being taken by another process between the read and
     * the write. A key expiring on its own is not a write, so it aborts nothing; the delete then
     * finds nothing to delete, which is the right outcome anyway.
     *
     * Only commands the rest of this class already uses, so the key prefix and the serializer are
     * applied by phpredis exactly as they are everywhere else. A lua script would be one round
     * trip instead of three, at the price of prefixing and serializing the arguments by hand.
     *
     * WATCH / MULTI / EXEC are the extension's own API rather than part of the cache store
     * contract, so this runs against the handler client() hands over.
     *
     * @param string   $redis_key Prefixed key
     * @param string   $token     Token this process holds
     * @param callable $queue     Receives the transaction, returns it with one command queued
     *
     * @return bool  False when the lock is not this process' any more, or the transaction aborted
     */
    private static function _guarded(string $redis_key, string $token, callable $queue): bool
    {
        $redis = self::_client()->client();

        try
        {
            $redis->watch($redis_key);

            if ( $redis->get($redis_key) !== $token )
            {
                $redis->unwatch();

                return false;
            }

            $result = $queue($redis->multi())->exec();
        }
        catch ( \Throwable $e )
        {
            // A connection left watching, or half way into a transaction, would carry that state
            // into every later command; this one is shared with the rest of the process
            self::_unwind($redis);

            throw $e;
        }

        return is_array($result) && !empty($result[0]);
    }

    /**
     * Take a connection out of WATCH or MULTI after something threw.
     *
     * @param \Redis|\RedisCluster $redis
     *
     * @return void
     */
    private static function _unwind($redis): void
    {
        try
        {
            $redis->discard();
        }
        catch ( \Throwable )
        {
            // Not in a transaction, which is one of the two ways to get here
        }

        try
        {
            $redis->unwatch();
        }
        catch ( \Throwable )
        {
            // Nothing watched, same story
        }
    }

    /**
     * Token identifying this holder: unique per lock, per process, per attempt.
     *
     * @return string
     */
    private static function _token(): string
    {
        return getmypid() . ':' . bin2hex(random_bytes(8));
    }
}
