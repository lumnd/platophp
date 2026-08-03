<?php

/**
 * MongoDB through the mongodb extension
 *
 * The extension's own Manager, Query and BulkWrite, not the mongodb/mongodb userland library: a
 * database driver is framework capability, but a composer SDK inside the framework package is not,
 * which is the same line Kafka is on (rdkafka, no SDK).
 *
 * Mongo is not SQL, so this driver ignores the grammar and translates the query object itself. The
 * fluent surface is the same as everywhere else for the part that maps -- where, order_by, limit,
 * offset, the aggregates -- and throws for the part that does not, rather than quietly returning
 * something that looks like an answer:
 *
 *     joins, unions, having, locks, index hints, raw SQL, JSON_SET, batch update by key
 *
 * For anything past a filter document, aggregate() takes a pipeline and command() takes a raw
 * command, so nothing here is a dead end.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\driver;

use plato\database\connection;
use plato\database\expression;
use plato\database\query;
use plato\runtime;
use Generator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class mongodb extends connection
{
    /**
     * SQL operator => Mongo query operator
     *
     * @var array<string, string>
     */
    protected array $_operators = [
        '='   => '$eq',
        '!='  => '$ne',
        '<>'  => '$ne',
        '>'   => '$gt',
        '>='  => '$gte',
        '<'   => '$lt',
        '<='  => '$lte',
    ];

    /**
     * The driver manager, built on first use.
     *
     * One manager for reads and writes both -- it owns its own pool and picks a node per operation
     * from the read preference, so a second one would only mean a second pool. It lives in
     * plato\runtime under the write key, like every other transport in this package, so a fork
     * hands the child a factory call instead of its parent's sockets.
     *
     * @return \MongoDB\Driver\Manager
     */
    protected function _handle(bool $write)
    {
        return runtime::share($this->_share_key('write'), function () {
            if ( !class_exists('\MongoDB\Driver\Manager') )
            {
                throw new RuntimeException(
                    'The mongodb connection needs ext-mongodb: pecl install mongodb',
                    3002
                );
            }

            $uri = (string) $this->config('uri', '');
            if ( $uri === '' )
            {
                $uri = sprintf(
                    'mongodb://%s:%d',
                    (string) $this->config('host', '127.0.0.1'),
                    (int) $this->config('port', 27017)
                );
            }

            $options = (array) $this->config('options', []);
            if ( $this->config('username') !== null )
            {
                $options += [
                    'username' => (string) $this->config('username'),
                    'password' => (string) $this->config('password', ''),
                ];
            }

            $class = '\MongoDB\Driver\Manager';

            return new $class($uri, $options, (array) $this->config('driver_options', []));
        }, fn () => $this->_forget_transaction());
    }

    protected function _disconnect(): void
    {
        runtime::forget($this->_share_key('write'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(query $q): array
    {
        $rows = [];
        foreach ( $this->cursor($q) as $row )
        {
            $rows[] = $row;
        }

        return $rows;
    }

    public function cursor(query $q): Generator
    {
        $this->_reject_unsupported($q);

        if ( $q->aggregate !== null )
        {
            return $this->_aggregate_cursor($q);
        }

        $filter  = $this->_filter($q->wheres);
        $options = $this->_find_options($q);

        return $this->_read($q, $filter, $options);
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return int|string The first document's _id
     */
    public function insert(query $q, array $rows)
    {
        if ( !$rows )
        {
            return 0;
        }

        $bulk = $this->_bulk();
        $ids  = [];
        foreach ( $rows as $row )
        {
            $ids[] = $bulk->insert($row);
        }

        $this->_write($q, $bulk, 'insert');

        $first = $ids[0] ?? ($rows[0]['_id'] ?? 0);

        return is_object($first) ? (string) $first : $first;
    }

    /**
     * Write the row where the query's conditions match, and insert it when they match nothing.
     *
     * @param  array<int, array<string, mixed>> $rows   Exactly one
     * @param  array<int|string, mixed>         $update Ignored: Mongo writes the whole row
     */
    public function upsert(query $q, array $rows, array $update): int
    {
        if ( count($rows) !== 1 )
        {
            throw new InvalidArgumentException('A MongoDB upsert takes one row and a where that selects it');
        }

        $this->_reject_unsupported($q);

        $bulk = $this->_bulk();
        $bulk->update(
            $this->_filter($q->wheres),
            ['$set' => $rows[0]],
            ['upsert' => true, 'multi' => false]
        );

        $result = $this->_write($q, $bulk, 'upsert');

        return $result->getModifiedCount() + $result->getUpsertedCount();
    }

    /**
     * @param  array<string,mixed> $values
     */
    public function update(query $q, array $values): int
    {
        if ( !$values )
        {
            throw new InvalidArgumentException('update() needs at least one field');
        }

        $this->_reject_unsupported($q);

        $bulk = $this->_bulk();
        $bulk->update($this->_filter($q->wheres), ['$set' => $values], ['multi' => true]);

        return $this->_write($q, $bulk, 'update')->getModifiedCount();
    }

    public function delete(query $q): int
    {
        $this->_reject_unsupported($q);

        $bulk = $this->_bulk();
        // limit 0 means every match, and a delete with no filter empties the collection, which is
        // what the caller asked for if they left the where off
        $bulk->delete($this->_filter($q->wheres), ['limit' => 0]);

        return $this->_write($q, $bulk, 'delete')->getDeletedCount();
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     */
    public function update_batch(query $q, array $rows, string $key): int
    {
        $this->_reject_unsupported($q);

        $bulk = $this->_bulk();
        foreach ( $rows as $row )
        {
            if ( !array_key_exists($key, $row) )
            {
                throw new InvalidArgumentException("update_batch(): every row needs a '{$key}' value");
            }

            $values = $row;
            unset($values[$key]);

            $bulk->update([$key => $row[$key]], ['$set' => $values], ['multi' => false]);
        }

        return $this->_write($q, $bulk, 'update_batch')->getModifiedCount();
    }

    /**
     * @param  array<string,mixed> $pairs
     */
    public function update_json(query $q, string $column, array $pairs): int
    {
        // Every Mongo document is already a document: set the paths directly
        $values = [];
        foreach ( $pairs as $path => $value )
        {
            $values[$column . '.' . str_replace('->', '.', (string) $path)] = $value;
        }

        return $this->update($q, $values);
    }

    /**
     * @param  array<int, mixed> $bindings
     * @param  bool              $write
     * @return array<int, array<string, mixed>>
     */
    public function select_raw(string $sql, array $bindings = [], bool $write = false): array
    {
        throw new RuntimeException('MongoDB takes no SQL. Use aggregate() or command()');
    }

    /**
     * @param  array<int, mixed> $bindings
     * @return int
     */
    public function statement(string $sql, array $bindings = []): int
    {
        throw new RuntimeException('MongoDB takes no SQL. Use aggregate() or command()');
    }

    /**
     * Run an aggregation pipeline.
     *
     * @param  array<int, array>  $pipeline
     * @param  array<string,mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function aggregate(string $collection, array $pipeline, array $options = []): array
    {
        $result = $this->command([
            'aggregate' => $this->table_prefix($collection),
            'pipeline'  => $pipeline,
            'cursor'    => (object) [],
        ] + $options);

        return $result[0]['cursor']['firstBatch'] ?? [];
    }

    /**
     * Run a raw database command.
     *
     * @param  array<string, mixed> $command
     * @return array<int, array<string, mixed>>
     */
    public function command(array $command): array
    {
        $label = (string) array_key_first($command);

        return $this->_attempt($label, [], true, function ($manager) use ($command) {
            $class  = '\MongoDB\Driver\Command';
            $cursor = $manager->executeCommand($this->_database(), new $class($command));
            $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

            return $cursor->toArray();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        $prefix = $this->prefix() === '' ? '' : $this->prefix() . '_';
        $tables = [];
        foreach ( $this->command(['listCollections' => 1]) as $row )
        {
            $batch = $row['cursor']['firstBatch'] ?? [];
            foreach ( $batch as $collection )
            {
                $name = (string) $collection['name'];

                $tables[] = [
                    'name'      => $name,
                    'module'    => $prefix !== '' && str_starts_with($name, $prefix)
                        ? substr($name, strlen($prefix))
                        : $name,
                    'comment'   => '',
                    'engine'    => (string) ($collection['type'] ?? 'collection'),
                    'rows'      => 0,
                    'collation' => '',
                ];
            }
        }

        return $tables;
    }

    /**
     * Turn the query's conditions into a filter document.
     *
     * SQL binds AND tighter than OR, so a flat list is cut at each OR and the pieces are joined
     * with $or; Mongo would otherwise read the whole list as one conjunction.
     *
     * @param  array<int, array<string, mixed>> $wheres
     * @return array<string, mixed>
     */
    protected function _filter(array $wheres): array
    {
        if ( !$wheres )
        {
            return [];
        }

        $groups  = [];
        $current = [];
        foreach ( $wheres as $i => $where )
        {
            if ( $i > 0 && $where['boolean'] === 'OR' )
            {
                $groups[] = $current;
                $current  = [];
            }

            $current[] = $this->_clause($where);
        }

        $groups[] = $current;

        $joined = [];
        foreach ( $groups as $group )
        {
            $joined[] = count($group) === 1 ? $group[0] : ['$and' => $group];
        }

        return count($joined) === 1 ? $joined[0] : ['$or' => $joined];
    }

    /**
     * @param  array<string, mixed> $where
     * @return array<string, mixed>
     */
    protected function _clause(array $where): array
    {
        switch ( $where['type'] )
        {
            case 'basic':
                return $this->_basic_clause($where);

            case 'in':
                if ( !is_array($where['values']) )
                {
                    throw new RuntimeException('MongoDB cannot take a subquery as an IN list');
                }

                return [$where['column'] => [($where['not'] ? '$nin' : '$in') => array_values($where['values'])]];

            case 'null':
                return [$where['column'] => [($where['not'] ? '$ne' : '$eq') => null]];

            case 'between':
                return $where['not']
                    ? ['$or' => [
                        [$where['column'] => ['$lt' => $where['min']]],
                        [$where['column'] => ['$gt' => $where['max']]],
                    ]]
                    : [$where['column'] => ['$gte' => $where['min'], '$lte' => $where['max']]];

            case 'column':
                return ['$expr' => [
                    $this->_expr_operator($where['operator']) => ['$' . $where['first'], '$' . $where['second']],
                ]];

            case 'nested':
                return $this->_filter($where['wheres']);

            case 'raw':
                // A raw fragment is SQL unless it is a filter document handed over as one
                if ( $where['sql'] === '' && isset($where['bindings'][0]) && is_array($where['bindings'][0]) )
                {
                    return $where['bindings'][0];
                }

                throw new RuntimeException(
                    'MongoDB cannot take a raw SQL condition. Pass a filter document as '
                    . "where_raw('', [\$filter]), or use aggregate()"
                );
        }

        throw new RuntimeException("MongoDB has no equivalent for a '{$where['type']}' condition");
    }

    /**
     * @param  array<string, mixed> $where
     * @return array<string, mixed>
     */
    protected function _basic_clause(array $where): array
    {
        $operator = strtolower(trim((string) $where['operator']));
        $value    = $where['value'];

        if ( $value instanceof expression || $value instanceof query )
        {
            throw new RuntimeException('MongoDB cannot take a SQL expression as a value');
        }

        if ( $operator === 'like' || $operator === 'not like' )
        {
            $regex = ['$regex' => $this->_like_to_regex((string) $value)];

            return [$where['column'] => $operator === 'like' ? $regex : ['$not' => $regex['$regex']]];
        }

        if ( !isset($this->_operators[$operator]) )
        {
            throw new RuntimeException("MongoDB has no equivalent for the '{$operator}' operator");
        }

        return [$where['column'] => [$this->_operators[$operator] => $value]];
    }

    protected function _expr_operator(string $operator): string
    {
        $operator = strtolower(trim($operator));
        if ( !isset($this->_operators[$operator]) )
        {
            throw new RuntimeException("MongoDB has no equivalent for the '{$operator}' operator");
        }

        return $this->_operators[$operator];
    }

    /**
     * SQL wildcards to a regular expression, with everything else taken literally.
     */
    protected function _like_to_regex(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);
        for ( $i = 0; $i < $len; $i++ )
        {
            $char = $pattern[$i];
            if ( $char === '%' )
            {
                $out .= '.*';
            }
            elseif ( $char === '_' )
            {
                $out .= '.';
            }
            else
            {
                $out .= preg_quote($char, '/');
            }
        }

        return '^' . $out . '$';
    }

    /**
     * @return array<string, mixed>
     */
    protected function _find_options(query $q): array
    {
        $options = [];

        if ( $q->columns )
        {
            $projection = [];
            foreach ( $q->columns as $column )
            {
                if ( !is_string($column) || stripos($column, ' as ') !== false )
                {
                    throw new RuntimeException('MongoDB cannot alias a field in a projection; use aggregate()');
                }

                if ( $column !== '*' )
                {
                    $projection[$column] = 1;
                }
            }

            if ( $projection )
            {
                $options['projection'] = $projection;
            }
        }

        if ( $q->orders )
        {
            $sort = [];
            foreach ( $q->orders as $order )
            {
                if ( isset($order['expression']) )
                {
                    throw new RuntimeException('MongoDB cannot sort by a SQL expression');
                }

                $sort[$order['column']] = $order['direction'] === 'DESC' ? -1 : 1;
            }

            $options['sort'] = $sort;
        }

        $limit = $q->limit;
        if ( $q->max_limit !== null )
        {
            $limit = $limit === null ? $q->max_limit : min($limit, $q->max_limit);
        }

        if ( $limit !== null )
        {
            $options['limit'] = max(0, (int) $limit);
        }

        if ( $q->offset !== null )
        {
            $options['skip'] = max(0, (int) $q->offset);
        }

        return $options;
    }

    protected function _reject_unsupported(query $q): void
    {
        $unsupported = [
            'join'        => (bool) $q->joins,
            'union'       => (bool) $q->unions,
            'HAVING'      => (bool) $q->havings,
            'row lock'    => $q->lock !== null,
            'index hint'  => $q->index_hint !== null,
            'GROUP BY'    => $q->groups && $q->aggregate === null,
        ];

        foreach ( $unsupported as $what => $used )
        {
            if ( $used )
            {
                throw new RuntimeException("MongoDB cannot do a {$what} through the query builder; "
                    . 'use aggregate() with a pipeline');
            }
        }
    }

    /**
     * @param  array<string, mixed> $filter
     * @param  array<string, mixed> $options
     */
    protected function _read(query $q, array $filter, array $options): Generator
    {
        $label = $this->_label('find', $q, $filter);

        $cursor = $this->_attempt($label, [], $q->use_master, function ($manager) use ($q, $filter, $options) {
            $class = '\MongoDB\Driver\Query';
            $found = $manager->executeQuery(
                $this->_namespace($q),
                new $class($filter, $options)
            );
            $found->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);

            return $found;
        });

        foreach ( $cursor as $document )
        {
            yield $document;
        }
    }

    /**
     * @param  object $bulk
     * @return \MongoDB\Driver\WriteResult
     */
    protected function _write(query $q, $bulk, string $what)
    {
        return $this->_attempt($this->_label($what, $q, []), [], true, function ($manager) use ($q, $bulk) {
            return $manager->executeBulkWrite($this->_namespace($q), $bulk);
        });
    }

    protected function _aggregate_cursor(query $q): Generator
    {
        $function   = strtolower((string) $q->aggregate['function']);
        $column     = (string) $q->aggregate['column'];
        $collection = $this->_collection($q);
        $filter     = $this->_filter($q->wheres);

        if ( $function === 'count' )
        {
            $command = ['count' => $collection, 'query' => $filter];
            if ( $q->limit !== null )
            {
                $command['limit'] = $q->limit;
            }

            if ( $q->offset !== null )
            {
                $command['skip'] = $q->offset;
            }

            $result = $this->command($command);

            yield ['aggregate' => (int) ($result[0]['n'] ?? 0)];

            return;
        }

        $pipeline = [];
        if ( $filter )
        {
            $pipeline[] = ['$match' => $filter];
        }

        $pipeline[] = ['$group' => ['_id' => null, 'aggregate' => ['$' . $function => '$' . $column]]];

        $rows = $this->aggregate($collection, $pipeline);

        yield ['aggregate' => $rows ? ($rows[0]['aggregate'] ?? null) : null];
    }

    /**
     * @return object
     */
    protected function _bulk()
    {
        $class = '\MongoDB\Driver\BulkWrite';

        return new $class(['ordered' => (bool) $this->config('ordered', true)]);
    }

    protected function _collection(query $q): string
    {
        if ( !is_string($q->table) || $q->table === '' )
        {
            throw new InvalidArgumentException('MongoDB needs a collection name');
        }

        return $this->table_prefix($q->table);
    }

    protected function _namespace(query $q): string
    {
        return $this->_database() . '.' . $this->_collection($q);
    }

    protected function _database(): string
    {
        $database = (string) $this->config('database', '');
        if ( $database === '' )
        {
            throw new RuntimeException('The mongodb connection has no database set');
        }

        return $database;
    }

    /**
     * What lands in the query log, since there is no statement to record.
     *
     * @param  array<string, mixed> $filter
     */
    protected function _label(string $what, query $q, array $filter): string
    {
        return sprintf(
            '%s %s %s',
            strtoupper($what),
            is_string($q->table) ? $this->table_prefix($q->table) : '?',
            $filter ? json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
    }

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    protected function _fetch($handle, string $sql, array $bindings): array
    {
        throw new RuntimeException('MongoDB takes no SQL');
    }

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     */
    protected function _affect($handle, string $sql, array $bindings): int
    {
        throw new RuntimeException('MongoDB takes no SQL');
    }

    /**
     * @param  mixed             $handle
     * @param  array<int, mixed> $bindings
     * @return int|string
     */
    protected function _insert($handle, string $sql, array $bindings)
    {
        throw new RuntimeException('MongoDB takes no SQL');
    }

    protected function _is_lost_connection(Throwable $e): bool
    {
        return $e instanceof \MongoDB\Driver\Exception\ConnectionException;
    }
}
