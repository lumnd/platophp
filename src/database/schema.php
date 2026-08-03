<?php

/**
 * Schema builder facade: create, alter and drop tables without writing dialect SQL
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use Closure;

/**
 * What a migration talks to.
 *
 *     use plato\database\blueprint;
 *     use plato\database\migration;
 *     use plato\database\schema;
 *
 *     return new class extends migration
 *     {
 *         public function up(): void
 *         {
 *             schema::create('user', function (blueprint $table)
 *             {
 *                 $table->id();
 *                 $table->string('email', 190)->unique();
 *                 $table->timestamps();
 *             });
 *         }
 *
 *         public function down(): void
 *         {
 *             schema::drop_if_exists('user');
 *         }
 *     };
 *
 * The split is: `blueprint` records what the table should look like, the connection's `grammar`
 * turns that into statements, and this class runs them. Nothing dialect specific lives here, so a
 * migration written against MySQL compiles for ClickHouse as far as ClickHouse can express it --
 * and where it cannot, the grammar throws while compiling instead of sending SQL that fails on the
 * server with a message about a different problem.
 *
 * **DDL does not roll back on MySQL.** Every statement is committed as it runs, so a `create()`
 * that fails halfway leaves whatever ran before it in place; `sql()` is there to see exactly what
 * a blueprint would send before it sends it.
 */
class schema
{
    /**
     * Connection every call uses when none is named
     *
     * @var string|null
     */
    private static $_connection = null;

    /**
     * Use this connection for the calls that follow.
     *
     * @param  string|null $name Null goes back to the default
     */
    public static function on(?string $name): void
    {
        self::$_connection = $name;
    }

    /**
     * Create a table.
     *
     * @param  string  $table    Table name; #PB# and the configured prefix both apply
     * @param  Closure $callback Receives the blueprint
     * @param  string|null $name Connection
     *
     * @return void
     */
    public static function create(string $table, Closure $callback, ?string $name = null): void
    {
        $blueprint = new blueprint($table, 'create');
        $callback($blueprint);

        self::_run($blueprint, $name);
    }

    /**
     * Change a table that already exists.
     *
     * @param  Closure     $callback Receives the blueprint
     * @param  string|null $name     Connection
     *
     * @return void
     */
    public static function table(string $table, Closure $callback, ?string $name = null): void
    {
        $blueprint = new blueprint($table, 'alter');
        $callback($blueprint);

        self::_run($blueprint, $name);
    }

    /**
     * @param  string|null $name Connection
     * @return void
     */
    public static function drop(string $table, ?string $name = null): void
    {
        self::_run(new blueprint($table, 'drop'), $name);
    }

    /**
     * @param  string|null $name Connection
     * @return void
     */
    public static function drop_if_exists(string $table, ?string $name = null): void
    {
        self::_run(new blueprint($table, 'drop_if_exists'), $name);
    }

    /**
     * @param  string|null $name Connection
     * @return void
     */
    public static function rename(string $from, string $to, ?string $name = null): void
    {
        $blueprint = new blueprint($from, 'alter');
        $blueprint->rename($to);

        self::_run($blueprint, $name);
    }

    /**
     * @param  string|null $name Connection
     * @return bool
     */
    public static function has_table(string $table, ?string $name = null): bool
    {
        $connection = self::_connection($name);

        list($sql, $bindings) = $connection->grammar()->compile_table_exists(
            $connection->table_prefix($table)
        );

        return $connection->select_raw($sql, $bindings, true) !== [];
    }

    /**
     * @param  string|null $name Connection
     * @return bool
     */
    public static function has_column(string $table, string $column, ?string $name = null): bool
    {
        $connection = self::_connection($name);

        list($sql, $bindings) = $connection->grammar()->compile_column_exists(
            $connection->table_prefix($table),
            $column
        );

        return $connection->select_raw($sql, $bindings, true) !== [];
    }

    /**
     * The statements a blueprint would send, without sending them.
     *
     * The way to review a migration before trusting it to a production database, and the way the
     * unit tests assert on the dialect without needing a server.
     *
     * @param  string      $action   create|alter
     * @param  string|null $name     Connection
     *
     * @return array<int, string>
     */
    public static function sql(string $table, Closure $callback, string $action = 'create', ?string $name = null): array
    {
        $blueprint = new blueprint($table, $action);
        $callback($blueprint);

        return self::_connection($name)->grammar()->compile_schema($blueprint);
    }

    /**
     * Compile a blueprint and run every statement it produced.
     *
     * @param  string|null $name Connection
     *
     * @return void
     */
    private static function _run(blueprint $blueprint, ?string $name = null): void
    {
        $connection = self::_connection($name);

        foreach ( $connection->grammar()->compile_schema($blueprint) as $sql )
        {
            $connection->statement($sql);
        }
    }

    /**
     * @return connection
     */
    private static function _connection(?string $name = null): connection
    {
        return connection::instance($name ?? self::$_connection);
    }
}
