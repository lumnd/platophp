<?php

/**
 * MySQL and MariaDB over PDO
 *
 * Two handles: one for the write endpoint, one for a read endpoint picked at random from the read
 * list. A read inside a transaction goes to the write handle, or it would not see what the
 * transaction has written. Neither handle is dialled until a statement needs it.
 *
 * Prepared statements are real ones: emulation is off, so values travel to the server separately
 * from the statement and the placeholder count is checked there. Values bind by their PHP type,
 * which means passing '5' for an integer column, or 5 for a varchar one, leaves the server to
 * convert -- and a converted column cannot use its index. Pass values in the column's own type.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\driver;

use plato\database\connection;
use plato\runtime;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

class mysql extends connection
{
    /**
     * MySQL error codes worth another attempt with a fresh socket
     *
     * @var array<int, int>
     */
    protected array $_lost_connection_codes = [2006, 2013, 2055, 4031];

    /**
     * Deadlock, and lock wait timeout
     *
     * @var array<int, int>
     */
    protected array $_deadlock_codes = [1213, 1205];

    /**
     * The PDO for a statement, dialled on first use.
     *
     * Both handles live in plato\runtime rather than in a property of this object, so that a fork
     * hands the child a factory call instead of its parent's socket. Asked for on every statement
     * rather than cached here: that call is what notices the fork.
     *
     * @return PDO
     */
    protected function _handle(bool $write)
    {
        $read = $this->_read_endpoints();

        // A read that is part of a transaction has to see the transaction's own writes
        if ( $write || $this->in_transaction() || !$read )
        {
            return runtime::share(
                $this->_share_key('write'),
                fn (): PDO => $this->_dial($this->_write_endpoint()),
                // The registry releasing the socket -- runtime::flush() before a fork, as much as
                // disconnect() -- ends whatever transaction was open on it. A depth left standing
                // would have the next statement believe it is still inside one, and commit()
                // confirm a transaction the new socket never opened
                fn () => $this->_forget_transaction()
            );
        }

        return runtime::share(
            $this->_share_key('read'),
            fn (): PDO => $this->_dial($read[array_rand($read)])
        );
    }

    /**
     * @param  PDO               $handle
     * @param  array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    protected function _fetch($handle, string $sql, array $bindings): array
    {
        $statement = $this->_prepare($handle, $sql, $bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param  PDO               $handle
     * @param  array<int, mixed> $bindings
     */
    protected function _affect($handle, string $sql, array $bindings): int
    {
        if ( !$bindings )
        {
            // DDL and other statements the server will not prepare
            return (int) $handle->exec($sql);
        }

        return $this->_prepare($handle, $sql, $bindings)->rowCount();
    }

    /**
     * @param  PDO               $handle
     * @param  array<int, mixed> $bindings
     * @return int|string
     */
    protected function _insert($handle, string $sql, array $bindings)
    {
        $this->_prepare($handle, $sql, $bindings);

        $id = $handle->lastInsertId();

        // A table with no auto increment column answers 0, and an id past PHP_INT_MAX stays a
        // string rather than losing its low digits to a float
        return is_string($id) && $id !== '' && (string) (int) $id === $id ? (int) $id : $id;
    }

    /**
     * @param  PDO               $handle
     * @param  array<int, mixed> $bindings
     */
    protected function _cursor($handle, string $sql, array $bindings): Generator
    {
        $statement = $this->_prepare($handle, $sql, $bindings);

        while ( ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false )
        {
            yield $row;
        }

        $statement->closeCursor();
    }

    /**
     * Drop both handles.
     *
     * runtime::forget() and not `= null`: in a child that inherited them the entries are not in
     * the map any more -- the registry moved them aside on the fork -- so there is nothing here to
     * destroy, and PDO's teardown never runs against a descriptor the parent is still using.
     */
    protected function _disconnect(): void
    {
        runtime::forget($this->_share_key('write'));
        runtime::forget($this->_share_key('read'));
    }

    /**
     * @param  PDO $handle
     */
    protected function _begin($handle): bool
    {
        return $handle->beginTransaction();
    }

    /**
     * @param  PDO $handle
     */
    protected function _commit($handle): bool
    {
        return $handle->inTransaction() ? $handle->commit() : false;
    }

    /**
     * @param  PDO $handle
     */
    protected function _rollback($handle): bool
    {
        return $handle->inTransaction() ? $handle->rollBack() : false;
    }

    /**
     * @param  PDO    $handle
     */
    protected function _savepoint($handle, string $name): bool
    {
        return $handle->exec('SAVEPOINT ' . $name) !== false;
    }

    /**
     * @param  PDO    $handle
     */
    protected function _release_savepoint($handle, string $name): bool
    {
        return $handle->exec('RELEASE SAVEPOINT ' . $name) !== false;
    }

    /**
     * @param  PDO    $handle
     */
    protected function _rollback_savepoint($handle, string $name): bool
    {
        return $handle->exec('ROLLBACK TO SAVEPOINT ' . $name) !== false;
    }

    protected function _is_lost_connection(Throwable $e): bool
    {
        if ( in_array($this->_driver_code($e), $this->_lost_connection_codes, true) )
        {
            return true;
        }

        return $this->_message_has($e, [
            'server has gone away',
            'Lost connection',
            'Error while sending',
            'is dead or not enabled',
            'SSL connection has been closed unexpectedly',
            'Packets out of order',
            'no connection to the server',
            'connection is no longer usable',
        ]);
    }

    protected function _is_deadlock(Throwable $e): bool
    {
        if ( in_array($this->_driver_code($e), $this->_deadlock_codes, true) )
        {
            return true;
        }

        return $this->_message_has($e, ['Deadlock found', 'Lock wait timeout exceeded']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        $prefix = $this->prefix() === '' ? '' : $this->prefix() . '_';
        $tables = [];
        foreach ( $this->select_raw('SHOW TABLE STATUS', [], true) as $row )
        {
            $name = (string) $row['Name'];

            $tables[] = [
                'name'      => $name,
                'module'    => $prefix !== '' && str_starts_with($name, $prefix)
                    ? substr($name, strlen($prefix))
                    : $name,
                'comment'   => (string) ($row['Comment'] ?? ''),
                'engine'    => (string) ($row['Engine'] ?? ''),
                'rows'      => (int) ($row['Rows'] ?? 0),
                'collation' => (string) ($row['Collation'] ?? ''),
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    public function table_schema(string $table): array
    {
        $table = $this->table_prefix($table);
        if ( !preg_match('/^[A-Za-z0-9_$]+$/', $table) )
        {
            throw new InvalidArgumentException("'{$table}' is not a table name");
        }

        $info = null;
        foreach ( $this->tables() as $row )
        {
            if ( $row['name'] === $table )
            {
                $info = $row;
                break;
            }
        }

        if ( $info === null )
        {
            throw new InvalidArgumentException("Table '{$table}' does not exist");
        }

        $prefix = $this->prefix() === '' ? '' : $this->prefix() . '_';
        $info['model_table'] = $prefix !== '' && str_starts_with($table, $prefix)
            ? '#PB#_' . substr($table, strlen($prefix))
            : $table;

        $columns     = [];
        $primary_key = [];
        foreach ( $this->select_raw("SHOW FULL COLUMNS FROM `{$table}`", [], true) as $row )
        {
            preg_match('/^([a-zA-Z]+)(?:\(([^)]+)\))?/i', (string) $row['Type'], $match);
            $base_type = strtolower($match[1] ?? '');

            $columns[] = [
                'name'      => $row['Field'],
                'type'      => $row['Type'],
                'base_type' => $base_type,
                'length'    => in_array($base_type, ['char', 'varchar', 'binary', 'varbinary', 'bit'], true)
                    && isset($match[2]) ? (int) $match[2] : null,
                'unsigned'  => str_contains(strtolower((string) $row['Type']), 'unsigned'),
                'nullable'  => strtoupper((string) $row['Null']) === 'YES',
                'default'   => $row['Default'],
                'extra'     => (string) ($row['Extra'] ?? ''),
                'comment'   => (string) ($row['Comment'] ?? ''),
                'collation' => $row['Collation'] ?? null,
            ];

            if ( ($row['Key'] ?? '') === 'PRI' )
            {
                $primary_key[] = $row['Field'];
            }
        }

        $index_rows = $this->select_raw("SHOW INDEX FROM `{$table}`", [], true);
        usort($index_rows, function ($a, $b)
        {
            $name = strcmp((string) $a['Key_name'], (string) $b['Key_name']);

            return $name === 0 ? ((int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']) : $name;
        });

        $indexes = [];
        foreach ( $index_rows as $row )
        {
            $name = (string) $row['Key_name'];
            if ( !isset($indexes[$name]) )
            {
                $indexes[$name] = ['unique' => (int) $row['Non_unique'] === 0, 'columns' => []];
            }

            $indexes[$name]['columns'][] = $row['Column_name'];
        }

        $create = $this->select_raw("SHOW CREATE TABLE `{$table}`", [], true);

        return array_merge($info, [
            'primary_key' => $primary_key,
            'columns'     => $columns,
            'indexes'     => $indexes,
            'create_sql'  => $create[0]['Create Table'] ?? '',
        ]);
    }

    /**
     * Prepare, bind and execute.
     *
     * @param  array<int, mixed> $bindings
     */
    protected function _prepare(PDO $handle, string $sql, array $bindings): PDOStatement
    {
        $statement = $handle->prepare($sql);

        $position = 1;
        foreach ( $bindings as $value )
        {
            $statement->bindValue($position++, $value, $this->_param_type($value));
        }

        $statement->execute();

        return $statement;
    }

    /**
     * @param  mixed $value
     */
    protected function _param_type($value): int
    {
        if ( $value === null )
        {
            return PDO::PARAM_NULL;
        }

        if ( is_int($value) )
        {
            return PDO::PARAM_INT;
        }

        if ( is_bool($value) )
        {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }

    /**
     * Open one connection.
     *
     * @param  array<string, mixed> $endpoint
     */
    protected function _dial(array $endpoint): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, and ints and floats that come back as ints and floats
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => (int) $this->config('timeout', 3),
            PDO::ATTR_PERSISTENT         => (bool) $this->config('persistent', false),
        ];

        foreach ( (array) $this->config('options', []) as $key => $value )
        {
            $options[$key] = $value;
        }

        try
        {
            $handle = new PDO(
                $this->_dsn($endpoint),
                (string) $this->config('username', ''),
                (string) $this->config('password', ''),
                $options
            );
        }
        catch ( PDOException $e )
        {
            throw new RuntimeException(sprintf(
                '%s [%s:%s]',
                $e->getMessage(),
                $endpoint['host'] ?? '',
                $endpoint['port'] ?? ''
            ), 3001, $e);
        }

        foreach ( $this->_session_statements() as $statement )
        {
            $handle->exec($statement);
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed> $endpoint
     */
    protected function _dsn(array $endpoint): string
    {
        $parts = [];
        if ( !empty($endpoint['socket']) )
        {
            $parts[] = 'unix_socket=' . $endpoint['socket'];
        }
        else
        {
            $parts[] = 'host=' . ($endpoint['host'] ?? '127.0.0.1');
            $parts[] = 'port=' . (int) ($endpoint['port'] ?? 3306);
        }

        if ( $this->config('database') )
        {
            $parts[] = 'dbname=' . $this->config('database');
        }

        $parts[] = 'charset=' . $this->config('charset', 'utf8mb4');

        return 'mysql:' . implode(';', $parts);
    }

    /**
     * Session settings applied to a fresh connection.
     *
     * @return array<int, string>
     */
    protected function _session_statements(): array
    {
        $statements = [];

        if ( $this->config('collation') )
        {
            $statements[] = sprintf(
                'SET NAMES %s COLLATE %s',
                $this->_quote_setting((string) $this->config('charset', 'utf8mb4')),
                $this->_quote_setting((string) $this->config('collation'))
            );
        }

        if ( $this->config('sql_mode') !== null )
        {
            $statements[] = 'SET SESSION sql_mode = ' . $this->_quote_setting((string) $this->config('sql_mode'));
        }

        if ( $this->config('timezone') !== null )
        {
            $statements[] = 'SET time_zone = ' . $this->_quote_setting((string) $this->config('timezone'));
        }

        if ( $this->config('group_concat_max_len') )
        {
            $statements[] = 'SET SESSION group_concat_max_len = ' . (int) $this->config('group_concat_max_len');
        }

        return $statements;
    }

    /**
     * Config values reach a SET statement, which takes no placeholders, so they are checked here
     * rather than escaped.
     */
    protected function _quote_setting(string $value): string
    {
        if ( !preg_match('/^[A-Za-z0-9_,+:\-\/ ]*$/', $value) )
        {
            throw new InvalidArgumentException("'{$value}' is not a usable session setting");
        }

        return "'" . $value . "'";
    }

    /**
     * The endpoint writes go to: the 'write' block when there is one, else the connection's own
     * host and port.
     *
     * @return array<string, mixed>
     */
    protected function _write_endpoint(): array
    {
        $settings = $this->config('write');

        return $this->_endpoint(is_array($settings) && $settings ? $settings : $this->config());
    }

    /**
     * Endpoints reads may go to. Empty means there are no replicas and reads use the write handle.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function _read_endpoints(): array
    {
        $settings = $this->config('read');
        if ( !$settings )
        {
            return [];
        }

        // One endpoint, or a list of them
        $list = [];
        foreach ( isset($settings['host']) || isset($settings['socket']) ? [$settings] : (array) $settings as $one )
        {
            if ( is_array($one) && ($one['host'] ?? $one['socket'] ?? null) )
            {
                $list[] = $this->_endpoint($one);
            }
            elseif ( is_string($one) && $one !== '' )
            {
                $list[] = $this->_endpoint(['host' => $one]);
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function _endpoint(array $settings): array
    {
        $host = (string) ($settings['host'] ?? '127.0.0.1');
        $port = $settings['port'] ?? $this->config('port', 3306);

        if ( strpos($host, ':') !== false && substr_count($host, ':') === 1 )
        {
            list($host, $port) = explode(':', $host, 2);
        }

        return [
            'host'   => $host,
            'port'   => (int) $port,
            'socket' => $settings['socket'] ?? $this->config('socket'),
        ];
    }

    /**
     * The server's own error number, as opposed to the SQLSTATE.
     */
    protected function _driver_code(Throwable $e): int
    {
        $previous = $e->getPrevious();
        foreach ( [$e, $previous] as $candidate )
        {
            if ( $candidate instanceof PDOException && isset($candidate->errorInfo[1]) )
            {
                return (int) $candidate->errorInfo[1];
            }
        }

        return 0;
    }

    /**
     * @param  array<int, string> $needles
     */
    protected function _message_has(Throwable $e, array $needles): bool
    {
        $message = $e->getMessage() . ($e->getPrevious() ? ' ' . $e->getPrevious()->getMessage() : '');
        foreach ( $needles as $needle )
        {
            if ( stripos($message, $needle) !== false )
            {
                return true;
            }
        }

        return false;
    }
}
