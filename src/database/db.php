<?php

/**
 * Database facade: reach a connection, start a query, run a statement
 *
 * A thin static front for the objects underneath. Everything here delegates to a connection, and
 * the connection and query objects can be built and used directly -- which is how they are tested,
 * and how a host that wants two databases side by side keeps them apart.
 *
 *     db::table('#PB#_user')->where('id', 5)->first();
 *     db::table('#PB#_user')->insert(['name' => 'x']);
 *     db::connection('clickhouse')->table('events')->where('day', '>=', $from)->get();
 *     db::transaction(fn() => ...);
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use Closure;

class db
{
    /**
     * Statements run so far, newest last, each one
     * ['connection' => string, 'sql' => string, 'bindings' => array, 'time' => float, 'write' => bool]
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $_queries = [];

    /**
     * Whether to keep the query log at all. Null asks debug mode.
     */
    private static ?bool $_log = null;

    /**
     * How many statements to keep. A worker that runs for hours would otherwise grow a log until
     * the process dies of it.
     */
    private static int $_log_limit = 1000;

    /**
     * @return connection
     */
    public static function connection(?string $name = null): connection
    {
        return connection::instance($name);
    }

    /**
     * Start a query.
     *
     * @param  string|null $name Connection to run it on
     * @return query
     */
    public static function table(string $table, ?string $name = null): query
    {
        return connection::instance($name)->table($table);
    }

    /**
     * @param  array<int, mixed> $bindings
     * @param  string|null       $name
     * @return array<int, array<string, mixed>>
     */
    public static function select_raw(string $sql, array $bindings = [], ?string $name = null): array
    {
        return connection::instance($name)->select_raw($sql, $bindings);
    }

    /**
     * @param  array<int, mixed> $bindings
     * @param  string|null       $name
     * @return int Rows affected
     */
    public static function statement(string $sql, array $bindings = [], ?string $name = null): int
    {
        return connection::instance($name)->statement($sql, $bindings);
    }

    /**
     * @param  int         $attempts Retries of the whole closure on a deadlock
     * @param  string|null $name
     * @return mixed
     */
    public static function transaction(Closure $callback, int $attempts = 1, ?string $name = null)
    {
        return connection::instance($name)->transaction($callback, $attempts);
    }

    /**
     * @return bool
     */
    public static function begin(?string $name = null): bool
    {
        return connection::instance($name)->begin();
    }

    /**
     * @return bool
     */
    public static function commit(?string $name = null): bool
    {
        return connection::instance($name)->commit();
    }

    /**
     * @return bool
     */
    public static function rollback(?string $name = null): bool
    {
        return connection::instance($name)->rollback();
    }

    /**
     * A fragment of SQL the compiler leaves alone. Nothing in it is escaped, so it must not be
     * built out of request data; values belong in $bindings.
     *
     * @param  array<int, mixed> $bindings
     * @return expression
     */
    public static function raw(string $value, array $bindings = []): expression
    {
        return new expression($value, $bindings);
    }

    /**
     * @return string
     */
    public static function prefix(?string $name = null): string
    {
        return connection::instance($name)->prefix();
    }

    /**
     * Expand #PB# in a statement, or return the prefix itself.
     *
     * @param  string|null $name
     * @return string
     */
    public static function table_prefix(?string $sql = null, ?string $name = null): string
    {
        return connection::instance($name)->table_prefix($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tables(?string $name = null): array
    {
        return connection::instance($name)->tables();
    }

    /**
     * @return array<string, mixed>
     */
    public static function table_schema(string $table, ?string $name = null): array
    {
        return connection::instance($name)->table_schema($table);
    }

    /**
     * Choose the connection an omitted name resolves to.
     *
     * @param  string|null $name Null puts config/database.php back in charge
     */
    public static function set_default(?string $name): void
    {
        connection::set_default($name);
    }

    /**
     * @return void
     */
    public static function purge(?string $name = null): void
    {
        connection::purge($name);
    }

    /**
     * Roll back a transaction the last request left open, see connection::discard_transactions().
     *
     * plato::reset_request() calls this before a resident worker takes the next message.
     *
     * @return array<string, int> Depth each connection was left at, keyed by connection name
     */
    public static function discard_transactions(): array
    {
        return connection::discard_transactions();
    }

    /**
     * Called by connection for every statement it runs.
     *
     * @param  array<int, mixed> $bindings
     */
    public static function record(string $name, string $sql, array $bindings, float $seconds, bool $write): void
    {
        if ( !self::logging() )
        {
            return;
        }

        if ( count(self::$_queries) >= self::$_log_limit )
        {
            array_shift(self::$_queries);
        }

        self::$_queries[] = [
            'connection' => $name,
            'sql'        => $sql,
            'bindings'   => $bindings,
            'time'       => round($seconds, 6),
            'write'      => $write,
        ];
    }

    public static function logging(): bool
    {
        if ( self::$_log !== null )
        {
            return self::$_log;
        }

        // Under a web request the profiler may want them; a CLI worker only pays for it in debug
        return PHP_SAPI !== 'cli' || \plato\plato::debug();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function last_query(): ?array
    {
        return self::$_queries ? self::$_queries[count(self::$_queries) - 1] : null;
    }

    /**
     * @return float Seconds spent in every statement recorded
     */
    public static function total_time(): float
    {
        $total = 0.0;
        foreach ( self::$_queries as $query )
        {
            $total += (float) $query['time'];
        }

        return round($total, 6);
    }

    public static function flush_log(): void
    {
        self::$_queries = [];
    }

    /**
     * Statements recorded during the current request.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function queries(): array
    {
        return self::$_queries;
    }

    /**
     * Override whether statements are recorded; null follows framework debug mode.
     */
    public static function set_logging(?bool $enabled): void
    {
        self::$_log = $enabled;
    }

    public static function logging_override(): ?bool
    {
        return self::$_log;
    }

    /**
     * Limit the request-local query log.
     */
    public static function set_log_limit(int $limit): void
    {
        self::$_log_limit = max(1, $limit);
    }

    public static function log_limit(): int
    {
        return self::$_log_limit;
    }
}
