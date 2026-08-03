<?php

/**
 * A named connection: config, instance registry, retries, transactions, query log
 *
 * One object per logical connection, holding a write handle and a read handle rather than being
 * registered twice under a _w and a _r name. Nothing here opens a socket: the handle is built on
 * the first statement that needs it, so mentioning a connection costs nothing.
 *
 * Everything that is the same for every engine lives here. A driver supplies the transport
 * (_handle, _fetch, _affect, _insert, _cursor) and answers whether a given failure is worth
 * retrying; a non SQL engine (see driver\mongodb) overrides the verbs instead.
 *
 * Fork safety is not this class' own invention: the transports live in plato\runtime under
 * `database.<name>.write` and `database.<name>.read`, the same registry the cache, the queue and
 * the log use. A child that inherited a parent's socket gets a fresh one from the factory, and the
 * one it inherited is **abandoned rather than closed** -- running PDO's teardown on a descriptor
 * the parent is still using would end the parent's session. What is left here is the transaction
 * depth, which a fork also invalidates and which is not a resource: _guard_fork() compares the
 * runtime epoch and resets it.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use plato\config;
use plato\event;
use plato\log;
use plato\runtime;
use Closure;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

abstract class connection
{
    /**
     * Live connections, keyed by name
     *
     * @var array<string, connection>
     */
    protected static array $_instances = [];

    /**
     * Driver name => class
     *
     * @var array<string, class-string<connection>>
     */
    protected static array $_drivers = [
        'mysql'      => driver\mysql::class,
        'mariadb'    => driver\mysql::class,
        'clickhouse' => driver\clickhouse::class,
        'mongodb'    => driver\mongodb::class,
    ];

    /**
     * Connection instance() answers with when asked for none in particular; null until something
     * calls set_default(), in which case config/database.php decides
     */
    protected static ?string $_default = null;

    protected string $_name;

    /**
     * @var array<string, mixed>
     */
    protected array $_config;

    protected ?grammar $_grammar = null;

    /**
     * Serial number handed to connections make() built, so two ad-hoc connections carrying the
     * same label do not share one runtime entry
     */
    protected static int $_adhoc = 0;

    /**
     * Runtime scope of this connection's handles: what goes between `database.` and `.read` /
     * `.write`. The connection name for one instance() registered, a name of its own for one
     * make() built
     */
    protected string $_scope;

    /**
     * Runtime epoch the transaction depth belongs to
     */
    protected int $_epoch;

    /**
     * Transaction nesting depth; anything past the first is a savepoint
     */
    protected int $_transactions = 0;

    /**
     * @param array<string, mixed> $config
     * @param string|null          $scope Runtime scope of the handles, null to use $name
     */
    public function __construct(string $name, array $config, ?string $scope = null)
    {
        $this->_name   = $name;
        $this->_config = $config;
        $this->_scope  = $scope ?? $name;
        $this->_epoch  = runtime::epoch();
    }

    /**
     * Get a connection by name, building it from config/database.php the first time.
     *
     * @return connection
     */
    public static function instance(?string $name = null): connection
    {
        $config = self::_read_config();
        $name   = $name ?? static::$_default ?? (string) ($config['default'] ?? '');

        if ( $name === '' )
        {
            throw new RuntimeException('config/database.php sets no default connection', 3001);
        }

        if ( isset(self::$_instances[$name]) )
        {
            return self::$_instances[$name];
        }

        if ( !isset($config['connections'][$name]) || !is_array($config['connections'][$name]) )
        {
            throw new RuntimeException("config/database.php has no '{$name}' connection", 3001);
        }

        $settings = $config['connections'][$name];
        $driver   = (string) ($settings['driver'] ?? '');
        if ( !isset(self::$_drivers[$driver]) )
        {
            throw new RuntimeException("'{$name}' asks for the unknown driver '{$driver}'", 3001);
        }

        $class = self::$_drivers[$driver];

        return self::$_instances[$name] = new $class($name, $settings);
    }

    /**
     * Build a connection from settings passed in rather than from config, for a host that resolves
     * them some other way. It is not registered, so instance() will not hand it out.
     *
     * The runtime scope carries a serial number rather than the label: two ad-hoc connections to
     * different servers are routinely given the same one, and sharing `database.ad-hoc.write`
     * between them would hand the second caller the first one's socket.
     *
     * @param  array<string, mixed> $settings
     * @param  string               $name Label for logs
     * @return connection
     */
    public static function make(string $driver, array $settings, string $name = 'ad-hoc'): connection
    {
        if ( !isset(self::$_drivers[$driver]) )
        {
            throw new RuntimeException("Unknown driver '{$driver}'", 3001);
        }

        $class = self::$_drivers[$driver];

        return new $class($name, ['driver' => $driver] + $settings, $name . '#' . ++self::$_adhoc);
    }

    /**
     * Teach the registry another driver.
     *
     * @param  class-string<connection> $class
     */
    public static function register_driver(string $driver, string $class): void
    {
        if ( !is_subclass_of($class, self::class) )
        {
            throw new InvalidArgumentException("{$class} is not a " . self::class);
        }

        self::$_drivers[$driver] = $class;
    }

    /**
     * Choose the connection instance() answers with when asked for none in particular.
     *
     * @param  string|null $name Null puts config/database.php back in charge
     */
    public static function set_default(?string $name): void
    {
        static::$_default = $name;
    }

    /**
     * Close a connection and forget it, or all of them.
     *
     * @return void
     */
    public static function purge(?string $name = null): void
    {
        foreach ( self::$_instances as $key => $instance )
        {
            if ( $name === null || $key === $name )
            {
                $instance->disconnect();
                unset(self::$_instances[$key]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function _read_config(): array
    {
        $config = config::instance('database')->get();
        if ( !is_array($config) || empty($config['connections']) || !is_array($config['connections']) )
        {
            throw new RuntimeException('config/database.php must return a connections map', 3001);
        }

        return $config;
    }

    public function name(): string
    {
        return $this->_name;
    }

    public function driver(): string
    {
        return (string) ($this->_config['driver'] ?? '');
    }

    /**
     * @param  mixed       $default
     * @return mixed
     */
    public function config(?string $key = null, $default = null)
    {
        if ( $key === null )
        {
            return $this->_config;
        }

        return array_key_exists($key, $this->_config) ? $this->_config[$key] : $default;
    }

    public function prefix(): string
    {
        return (string) ($this->_config['prefix'] ?? '');
    }

    public function grammar(): grammar
    {
        if ( $this->_grammar === null )
        {
            $class          = $this->_grammar_class();
            $this->_grammar = new $class($this->prefix());
        }

        return $this->_grammar;
    }

    /**
     * Start a query against a table.
     */
    public function table(string $table): query
    {
        return new query($this, $table);
    }

    /**
     * Expand #PB# in a statement, or return the prefix itself.
     *
     * @return string
     */
    public function table_prefix(?string $sql = null): string
    {
        return $sql === null ? $this->prefix() : $this->grammar()->substitute_prefix($sql);
    }

    public function __toString(): string
    {
        return $this->_name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(query $q): array
    {
        list($sql, $bindings) = $this->grammar()->compile_select($q);

        return $this->select_raw($sql, $bindings, $q->use_master);
    }

    public function cursor(query $q): Generator
    {
        list($sql, $bindings) = $this->grammar()->compile_select($q);

        return $this->_attempt($sql, $bindings, $q->use_master, function ($handle, $sql, $bindings) {
            return $this->_cursor($handle, $sql, $bindings);
        });
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return int|string Auto increment value of the first row written
     */
    public function insert(query $q, array $rows)
    {
        if ( !$rows )
        {
            return 0;
        }

        list($sql, $bindings) = $this->grammar()->compile_insert($q, $rows);

        return $this->_attempt($sql, $bindings, true, function ($handle, $sql, $bindings) {
            return $this->_insert($handle, $sql, $bindings);
        });
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @param  array<int|string, mixed>         $update
     */
    public function upsert(query $q, array $rows, array $update): int
    {
        if ( !$rows )
        {
            return 0;
        }

        list($sql, $bindings) = $this->grammar()->compile_insert($q, $rows, $update);

        return $this->statement($sql, $bindings);
    }

    /**
     * @param  array<string,mixed> $values
     */
    public function update(query $q, array $values): int
    {
        list($sql, $bindings) = $this->grammar()->compile_update($q, $values);

        return $this->statement($sql, $bindings);
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     */
    public function update_batch(query $q, array $rows, string $key): int
    {
        if ( !$rows )
        {
            return 0;
        }

        list($sql, $bindings) = $this->grammar()->compile_update_batch($q, $rows, $key);

        return $this->statement($sql, $bindings);
    }

    /**
     * @param  array<string,mixed> $pairs
     */
    public function update_json(query $q, string $column, array $pairs): int
    {
        list($sql, $bindings) = $this->grammar()->compile_update_json($q, $column, $pairs);

        return $this->statement($sql, $bindings);
    }

    public function delete(query $q): int
    {
        list($sql, $bindings) = $this->grammar()->compile_delete($q);

        return $this->statement($sql, $bindings);
    }

    /**
     * Run a statement that returns rows. #PB# is expanded; values belong in $bindings.
     *
     * @param  array<int, mixed> $bindings
     * @param  bool              $write Read from the write connection
     * @return array<int, array<string, mixed>>
     */
    public function select_raw(string $sql, array $bindings = [], bool $write = false): array
    {
        $sql = $this->table_prefix($sql);

        return $this->_attempt($sql, $bindings, $write, function ($handle, $sql, $bindings) {
            return $this->_fetch($handle, $sql, $bindings);
        });
    }

    /**
     * Run a statement that does not return rows.
     *
     * @param  array<int, mixed> $bindings
     * @return int Rows affected, as far as the engine reports it
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $sql = $this->table_prefix($sql);

        return (int) $this->_attempt($sql, $bindings, true, function ($handle, $sql, $bindings) {
            return $this->_affect($handle, $sql, $bindings);
        });
    }

    /**
     * Run a closure inside a transaction, committing when it returns and rolling back when it
     * throws. Nested calls join the outer transaction through a savepoint.
     *
     * @param  Closure $callback Receives this connection
     * @param  int     $attempts How many times to retry the whole closure on a deadlock
     * @return mixed             Whatever the closure returned
     */
    public function transaction(Closure $callback, int $attempts = 1)
    {
        for ( $attempt = 1; ; $attempt++ )
        {
            $this->begin();

            try
            {
                $result = $callback($this);
                $this->commit();

                return $result;
            }
            catch ( Throwable $e )
            {
                $this->rollback();

                // A deadlock victim's transaction is already gone, so retrying the statement alone
                // would be wrong; the whole closure has to run again
                if ( $attempt < max(1, $attempts) && $this->_is_deadlock($e) && $this->_transactions === 0 )
                {
                    log::warning(sprintf('Deadlock, retrying transaction (%d): %s', $attempt, $e->getMessage()));
                    continue;
                }

                throw $e;
            }
        }
    }

    public function begin(): bool
    {
        $this->_guard_fork();

        if ( $this->_transactions === 0 )
        {
            $result = $this->_begin($this->_handle(true));
        }
        else
        {
            $result = $this->_savepoint($this->_handle(true), $this->_savepoint_name($this->_transactions));
        }

        $this->_transactions++;
        $this->_log('BEGIN', []);

        return $result;
    }

    public function commit(): bool
    {
        if ( $this->_transactions === 0 )
        {
            return false;
        }

        $this->_transactions--;
        $this->_log('COMMIT', []);

        // Releasing a savepoint commits nothing on its own; only the outermost level does
        return $this->_transactions === 0
            ? $this->_commit($this->_handle(true))
            : $this->_release_savepoint($this->_handle(true), $this->_savepoint_name($this->_transactions));
    }

    public function rollback(): bool
    {
        if ( $this->_transactions === 0 )
        {
            return false;
        }

        $this->_transactions--;
        $this->_log('ROLLBACK', []);

        return $this->_transactions === 0
            ? $this->_rollback($this->_handle(true))
            : $this->_rollback_savepoint($this->_handle(true), $this->_savepoint_name($this->_transactions));
    }

    public function in_transaction(): bool
    {
        return $this->_transactions > 0;
    }

    public function transaction_level(): int
    {
        return $this->_transactions;
    }

    /**
     * Drop the handles. The next statement dials again.
     */
    public function disconnect(): void
    {
        $this->_transactions = 0;
        $this->_disconnect();
    }

    /**
     * Tables (or their equivalent) in this database.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        throw new RuntimeException($this->driver() . ' cannot list tables', 3003);
    }

    /**
     * Columns, indexes and primary key of one table.
     *
     * @return array<string, mixed>
     */
    public function table_schema(string $table): array
    {
        throw new RuntimeException($this->driver() . ' cannot describe a table', 3003);
    }

    /**
     * The live transport, dialled if it is not up yet.
     *
     * @param  bool $write Whether the statement writes
     * @return mixed
     */
    abstract protected function _handle(bool $write);

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    abstract protected function _fetch($handle, string $sql, array $bindings): array;

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     */
    abstract protected function _affect($handle, string $sql, array $bindings): int;

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     * @return int|string
     */
    abstract protected function _insert($handle, string $sql, array $bindings);

    abstract protected function _disconnect(): void;

    /**
     * Row at a time reads. The fallback holds the whole result, which is what a driver that can do
     * better overrides.
     *
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     */
    protected function _cursor($handle, string $sql, array $bindings): Generator
    {
        foreach ( $this->_fetch($handle, $sql, $bindings) as $row )
        {
            yield $row;
        }
    }

    /**
     * @return class-string<grammar>
     */
    protected function _grammar_class(): string
    {
        return grammar::class;
    }

    /**
     * Whether the failure means the socket is gone and a fresh one would succeed.
     */
    protected function _is_lost_connection(Throwable $e): bool
    {
        return false;
    }

    /**
     * Whether the failure is a deadlock or a lock wait timeout.
     */
    protected function _is_deadlock(Throwable $e): bool
    {
        return false;
    }

    /**
     * @param  mixed $handle
     */
    protected function _begin($handle): bool
    {
        throw new RuntimeException($this->driver() . ' has no transactions', 3004);
    }

    /**
     * @param  mixed $handle
     */
    protected function _commit($handle): bool
    {
        throw new RuntimeException($this->driver() . ' has no transactions', 3004);
    }

    /**
     * @param  mixed $handle
     */
    protected function _rollback($handle): bool
    {
        throw new RuntimeException($this->driver() . ' has no transactions', 3004);
    }

    /**
     * @param  mixed  $handle
     */
    protected function _savepoint($handle, string $name): bool
    {
        throw new RuntimeException($this->driver() . ' has no savepoints', 3004);
    }

    /**
     * @param  mixed  $handle
     */
    protected function _release_savepoint($handle, string $name): bool
    {
        throw new RuntimeException($this->driver() . ' has no savepoints', 3004);
    }

    /**
     * @param  mixed  $handle
     */
    protected function _rollback_savepoint($handle, string $name): bool
    {
        throw new RuntimeException($this->driver() . ' has no savepoints', 3004);
    }

    protected function _savepoint_name(int $level): string
    {
        return 'plato_sp_' . $level;
    }

    /**
     * Run one statement, retrying the two failures that are worth retrying.
     *
     * @param  array<int, mixed> $bindings
     * @param  Closure           $work Receives the handle, the statement and its bindings
     * @return mixed
     */
    protected function _attempt(string $sql, array $bindings, bool $write, Closure $work)
    {
        $this->_guard_fork();

        $max     = max(0, (int) $this->config('max_retries', 2));
        $attempt = 0;

        while ( true )
        {
            $start = microtime(true);

            try
            {
                $result = $work($this->_handle($write), $sql, $bindings);

                $this->_log($sql, $bindings, microtime(true) - $start, $write);

                return $result;
            }
            catch ( Throwable $e )
            {
                $attempt++;

                // Reconnecting mid transaction would silently start a new one and lose everything
                // written so far, so a lost connection inside one has to surface
                $retry = $attempt <= $max && !$this->in_transaction() &&
                    ($this->_is_lost_connection($e) || $this->_is_deadlock($e));

                if ( $retry )
                {
                    log::warning(sprintf(
                        '%s retry %d/%d on %s: %s',
                        $this->_name,
                        $attempt,
                        $max,
                        $this->_is_deadlock($e) ? 'deadlock' : 'lost connection',
                        $e->getMessage()
                    ));

                    if ( $this->_is_deadlock($e) )
                    {
                        // Back off a little, and not by the same amount as every other loser
                        usleep(random_int(10000, 50000) * $attempt);
                    }
                    else
                    {
                        $this->_disconnect();
                    }

                    continue;
                }

                log::error(sprintf('%s: %s [%s]', $this->_name, $e->getMessage(), $sql), 'SQL Error');

                throw new RuntimeException(
                    sprintf('%s [%s]', $e->getMessage(), $sql),
                    is_int($e->getCode()) ? $e->getCode() : 0,
                    $e
                );
            }
        }
    }

    /**
     * Runtime key of one of this connection's transports.
     *
     * @param  string $role 'write' or 'read'
     */
    protected function _share_key(string $role): string
    {
        return 'database.' . $this->_scope . '.' . $role;
    }

    /**
     * Forget that a transaction is open, because the socket carrying it has gone.
     *
     * Registered as the closer of the write handle, so releasing it through the registry -- which
     * runtime::flush() does before plato\pool forks, not only disconnect() -- leaves no depth
     * behind for the next statement to act on.
     */
    protected function _forget_transaction(): void
    {
        $this->_transactions = 0;
    }

    /**
     * Forget what a fork invalidated but the registry cannot see.
     *
     * The sockets themselves need nothing here: they are plato\runtime entries, so the child's
     * first _handle() finds an empty map and dials for itself while the inherited handles are held
     * aside rather than destroyed. The transaction depth is not a resource and is not in the map,
     * and a child that believed it was inside its parent's transaction would issue a COMMIT for a
     * transaction it never opened.
     */
    protected function _guard_fork(): void
    {
        $epoch = runtime::epoch();

        if ( $epoch !== $this->_epoch )
        {
            $this->_transactions = 0;
            $this->_epoch        = $epoch;
        }
    }

    /**
     * Record a statement: query log, ON_SQL event, slow query warning.
     *
     * @param  array<int, mixed> $bindings
     * @param  bool              $write
     * @return void
     */
    protected function _log(string $sql, array $bindings, float $seconds = 0.0, bool $write = true): void
    {
        db::record($this->_name, $sql, $bindings, $seconds, $write);

        event::trigger(event::ON_SQL, [$sql, $this->_name, round($seconds, 6)]);

        $slow = (float) $this->config('slow_query', 0);
        if ( $slow > 0 && $seconds > $slow )
        {
            log::warning(sprintf(
                'Slow query [%s] %ss: %s',
                $this->_name,
                round($seconds, 6),
                $this->grammar()->interpolate($sql, $bindings)
            ));
        }
    }
}
