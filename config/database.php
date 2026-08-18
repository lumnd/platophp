<?php

/**
 * Database connections
 *
 * One entry per logical connection under 'connections', each naming the driver it speaks. Nothing
 * here opens a socket: a connection is dialled by the first statement that needs it, so an entry
 * that is never used costs nothing.
 *
 * Only mysql is always present. clickhouse and mongodb appear when their host is configured, so a
 * typo in a connection name fails with "no such connection" instead of quietly dialling a service
 * on localhost that was never meant to be part of this application.
 *
 * An application overrides any of this by putting its own config/database.php in place; the two
 * are merged recursively. Note that a list is merged by index, so the read replica list cannot be
 * shortened that way -- leave DB_SLAVE_HOST empty to have no replicas at all.
 *
 * Credentials come from .env and must not be written here.
 */

$connections = [
    'mysql' => [
        'driver'    => 'mysql',
        'host'      => $_ENV['DB_MASTER_HOST'] ?? '127.0.0.1',
        'port'      => $_ENV['DB_MASTER_PORT'] ?? 3306,
        'database'  => $_ENV['DB_DATABASE'] ?? '',
        'username'  => $_ENV['DB_USERNAME'] ?? '',
        'password'  => $_ENV['DB_PASSWORD'] ?? '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_general_ci',
        // Substituted for #PB# in table names and in raw statements
        'prefix'    => $_ENV['DB_PREFIX'] ?? '',
        // Connect timeout in seconds
        'timeout'   => $_ENV['DB_TIMEOUT'] ?? 3,

        // A statement slower than this is written to the warning log. 0 turns the warning off
        'slow_query' => 0.5,

        // Attempts to make after a lost connection or a deadlock, outside a transaction. Inside
        // one neither is retried: reconnecting would silently start a new transaction, and a
        // deadlock victim's transaction is already gone
        'max_retries' => 2,

        // Ceiling applied to every SELECT's LIMIT, null for none. Set it and a query that asks for
        // more, or for no limit at all, is capped -- which silently truncates results, so it is off
        // by default. query::max_select_limit() lifts it for one query
        'max_select_limit' => null,

        // Persistent connections stay open between requests and keep whatever session state, open
        // transaction or table lock the last request left behind. They also live in PDO's own pool
        // rather than in plato\runtime, so a forked worker is handed its parent's socket and the two
        // interleave on one session. Leave this off, and never turn it on under plato\pool
        'persistent' => false,
    ],
];

// A replica is only worth a second connection when it is a different server: DB_SLAVE_HOST pointing
// at the write host would otherwise open one socket per role to the same place
$slave_host = (string) ($_ENV['DB_SLAVE_HOST'] ?? '');
$slave_port = (int) ($_ENV['DB_SLAVE_PORT'] ?? 3306);
if (
    $slave_host !== '' &&
    ($slave_host !== (string) $connections['mysql']['host'] || $slave_port !== (int) $connections['mysql']['port'])
)
{
    $connections['mysql']['read'] = [
        ['host' => $slave_host, 'port' => $slave_port],
    ];
}

if (!empty($_ENV['CLICKHOUSE_HOST']))
{
    $connections['clickhouse'] = [
        'driver'   => 'clickhouse',
        'host'     => $_ENV['CLICKHOUSE_HOST'],
        'port'     => $_ENV['CLICKHOUSE_PORT'] ?? 8123,
        'database' => $_ENV['CLICKHOUSE_DATABASE'] ?? 'default',
        'username' => $_ENV['CLICKHOUSE_USERNAME'] ?? 'default',
        'password' => $_ENV['CLICKHOUSE_PASSWORD'] ?? '',
        'prefix'   => $_ENV['CLICKHOUSE_PREFIX'] ?? '',
        // An analytical query takes longer than a row lookup, so this is not the mysql timeout
        'timeout'  => $_ENV['CLICKHOUSE_TIMEOUT'] ?? 30,
        // Verify the TLS certificate when the endpoint is https
        'verify'   => true,
        // Settings sent with every statement, as ClickHouse spells them
        // 'settings' => ['max_execution_time' => 60],
        'slow_query'  => 5,
        'max_retries' => 1,
    ];
}

if (!empty($_ENV['MONGODB_URI']) || !empty($_ENV['MONGODB_HOST']))
{
    $connections['mongodb'] = [
        'driver'   => 'mongodb',
        // A full connection string wins over host and port, and is the only way to reach a replica
        // set or to pass driver options
        'uri'      => $_ENV['MONGODB_URI'] ?? '',
        'host'     => $_ENV['MONGODB_HOST'] ?? '127.0.0.1',
        'port'     => $_ENV['MONGODB_PORT'] ?? 27017,
        'database' => $_ENV['MONGODB_DATABASE'] ?? '',
        'username' => $_ENV['MONGODB_USERNAME'] ?? null,
        'password' => $_ENV['MONGODB_PASSWORD'] ?? '',
        'prefix'   => $_ENV['MONGODB_PREFIX'] ?? '',
        // Stop a bulk write at the first failure rather than carrying on with the rest
        'ordered'  => true,
        'slow_query'  => 0.5,
        'max_retries' => 2,
    ];
}

return [
    // Connection db::table() and db::connection() use when asked for none in particular
    'default'     => 'mysql',
    'connections' => $connections,
];
