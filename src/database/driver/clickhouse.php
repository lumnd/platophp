<?php

/**
 * ClickHouse over the HTTP interface
 *
 * Port 8123 rather than the MySQL wire protocol on 9004: the wire protocol exists but has no real
 * prepared statements and a reduced type map, so the HTTP interface is the supported path and the
 * one every ClickHouse client speaks. There is no persistent handle -- each statement is a POST --
 * so "reconnecting" after a failure just means sending it again, and there are no transactions.
 *
 * Reads come back as JSONEachRow, one JSON document per line, which keeps types intact without
 * having to parse a TSV against the column list.
 *
 * Two things to know before writing through this driver:
 *
 *   - insert() answers 0. ClickHouse has no auto increment, so there is no id to hand back; the
 *     row count is in the response summary, not in the return value.
 *   - Insert in batches of thousands, not one row per statement. A part is written per INSERT and
 *     the background merge has to deal with every one of them.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database\driver;

use plato\database\connection;
use plato\database\grammar;
use plato\http\client;
use RuntimeException;
use Throwable;

class clickhouse extends connection
{
    /**
     * Endpoint the statements are posted to, resolved once.
     *
     * A string and not a socket: this driver speaks HTTP through plato\http\client, which opens and
     * closes a connection per statement. There is nothing here for a fork to invalidate, which is
     * why -- alone among the drivers -- it keeps its handle in a property rather than in
     * plato\runtime.
     */
    protected ?string $_endpoint = null;

    /**
     * Summary the server reported for the last statement, when it sent one
     *
     * @var array<string, mixed>
     */
    protected array $_summary = [];

    /**
     * @return string
     */
    protected function _handle(bool $write)
    {
        if ( $this->_endpoint === null )
        {
            $url = (string) $this->config('url', '');
            if ( $url === '' )
            {
                $url = sprintf(
                    'http://%s:%d',
                    (string) $this->config('host', '127.0.0.1'),
                    (int) $this->config('port', 8123)
                );
            }

            $this->_endpoint = rtrim($url, '/') . '/';
        }

        return $this->_endpoint;
    }

    protected function _disconnect(): void
    {
        $this->_endpoint = null;
    }

    /**
     * @return class-string<grammar>
     */
    protected function _grammar_class(): string
    {
        return \plato\database\grammar\clickhouse::class;
    }

    /**
     * @param  string            $handle
     * @param  array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    protected function _fetch($handle, string $sql, array $bindings): array
    {
        $statement = $this->grammar()->interpolate($sql, $bindings);

        // Asking for a format twice is an error, so only add one when the caller did not
        if ( !preg_match('/\bFORMAT\s+[A-Za-z]+\s*;?\s*$/i', $statement) )
        {
            $statement = rtrim($statement, "; \t\n\r") . ' FORMAT JSONEachRow';
        }

        $body = $this->_post($handle, $statement);

        $rows = [];
        foreach ( explode("\n", trim($body)) as $line )
        {
            $line = trim($line);
            if ( $line === '' )
            {
                continue;
            }

            $row = json_decode($line, true);
            if ( !is_array($row) )
            {
                throw new RuntimeException('ClickHouse sent a row that is not JSON: ' . substr($line, 0, 200));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  string            $handle
     * @param  array<int, mixed> $bindings
     */
    protected function _affect($handle, string $sql, array $bindings): int
    {
        $this->_post($handle, $this->grammar()->interpolate($sql, $bindings));

        // A mutation reports nothing, an insert reports what it wrote
        return (int) ($this->_summary['written_rows'] ?? 0);
    }

    /**
     * @param  string            $handle
     * @param  array<int, mixed> $bindings
     * @return int
     */
    protected function _insert($handle, string $sql, array $bindings)
    {
        $this->_affect($handle, $sql, $bindings);

        // No auto increment, so nothing to hand back
        return 0;
    }

    /**
     * What the server reported about the last statement: read_rows, written_rows, elapsed_ns and
     * whatever else this version sends.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->_summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function tables(): array
    {
        $prefix = $this->prefix() === '' ? '' : $this->prefix() . '_';
        $rows   = $this->select_raw(
            'SELECT name, engine, total_rows, comment FROM system.tables WHERE database = ? ORDER BY name',
            [$this->_database()]
        );

        $tables = [];
        foreach ( $rows as $row )
        {
            $name = (string) $row['name'];

            $tables[] = [
                'name'      => $name,
                'module'    => $prefix !== '' && str_starts_with($name, $prefix)
                    ? substr($name, strlen($prefix))
                    : $name,
                'comment'   => (string) ($row['comment'] ?? ''),
                'engine'    => (string) ($row['engine'] ?? ''),
                'rows'      => (int) ($row['total_rows'] ?? 0),
                'collation' => '',
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
            throw new RuntimeException("Table '{$table}' does not exist");
        }

        $rows = $this->select_raw(
            'SELECT name, type, default_expression, comment, is_in_primary_key '
            . 'FROM system.columns WHERE database = ? AND table = ? ORDER BY position',
            [$this->_database(), $table]
        );

        $columns     = [];
        $primary_key = [];
        foreach ( $rows as $row )
        {
            $type = (string) $row['type'];

            $columns[] = [
                'name'      => $row['name'],
                'type'      => $type,
                'base_type' => strtolower(preg_replace('/\(.*$/', '', str_replace('Nullable', '', $type)) ?? ''),
                'length'    => null,
                'unsigned'  => str_starts_with($type, 'UInt'),
                'nullable'  => str_contains($type, 'Nullable'),
                'default'   => $row['default_expression'] !== '' ? $row['default_expression'] : null,
                'extra'     => '',
                'comment'   => (string) ($row['comment'] ?? ''),
                'collation' => null,
            ];

            if ( !empty($row['is_in_primary_key']) )
            {
                $primary_key[] = $row['name'];
            }
        }

        $create = $this->select_raw('SHOW CREATE TABLE ' . $this->grammar()->wrap($table));

        return array_merge($info, [
            'primary_key' => $primary_key,
            'columns'     => $columns,
            'indexes'     => [],
            'create_sql'  => $create[0]['statement'] ?? '',
        ]);
    }

    /**
     * Post one statement and hand back the response body.
     */
    protected function _post(string $endpoint, string $statement): string
    {
        $this->_summary = [];

        $params = ['database' => $this->_database()] + (array) $this->config('settings', []);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        // Credentials as headers rather than query parameters, so they stay out of the server's
        // own query_log and out of anything that records a URL
        if ( $this->config('username') !== null )
        {
            $headers[] = 'X-ClickHouse-User: ' . $this->config('username');
        }

        if ( $this->config('password') !== null )
        {
            $headers[] = 'X-ClickHouse-Key: ' . $this->config('password');
        }

        $response = (new client())->http_request([
            'url'        => $endpoint . '?' . http_build_query($params),
            'post'       => $statement,
            'timeout'    => (int) $this->config('timeout', 30),
            'header'     => $headers,
            'connection' => 'keep-alive',
            'option'     => [
                // Response headers carry the summary, so they have to come back with the body
                CURLOPT_HEADER         => true,
                CURLOPT_SSL_VERIFYPEER => (bool) $this->config('verify', true),
            ],
        ]);

        if ( $response['body'] === null )
        {
            throw new RuntimeException(sprintf(
                'ClickHouse unreachable at %s: %s',
                $endpoint,
                $response['info']['error'] ?? 'no response'
            ));
        }

        $size      = (int) ($response['head']['header_size'] ?? 0);
        $body      = substr((string) $response['body'], $size);
        $received  = substr((string) $response['body'], 0, $size);
        $status    = (int) ($response['head']['http_code'] ?? 0);

        if ( preg_match('/^X-ClickHouse-Summary:\s*(.+)$/mi', $received, $match) )
        {
            $summary        = json_decode(trim($match[1]), true);
            $this->_summary = is_array($summary) ? $summary : [];
        }

        if ( $status !== 200 )
        {
            throw new RuntimeException(sprintf('ClickHouse %d: %s', $status, trim($body)));
        }

        return $body;
    }

    protected function _database(): string
    {
        return (string) $this->config('database', 'default');
    }

    /**
     * Nothing is held open, so a failed request is worth one more try.
     */
    protected function _is_lost_connection(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'ClickHouse unreachable') !== false;
    }
}
