# Database

PlatoPHP provides MySQL/MariaDB, ClickHouse, and MongoDB drivers. Connections are opened lazily by the first real query and are managed across process boundaries by `plato\runtime`.

## Configuration

```php
// app/config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_MASTER_HOST'],
            'database' => $_ENV['DB_DATABASE'],
            'username' => $_ENV['DB_USERNAME'],
            'password' => $_ENV['DB_PASSWORD'],
            'charset' => 'utf8mb4',
        ],
    ],
];
```

## Query builder

```php
use plato\database\db;

$users = db::table('user')
    ->select('id', 'name')
    ->where('status', 1)
    ->where_in('role', ['admin', 'editor'])
    ->order_by('id', 'desc')
    ->page(2, 20)
    ->get();

$user = db::table('user')->where('id', 7)->first();
$id = db::table('user')->insert(['name' => 'Ada']);
$changed = db::table('user')->where('id', 7)->update(['status' => 1]);
$deleted = db::table('user')->where('id', 7)->delete();
```

Values use prepared statement bindings by default. Pass values as bindings to `where_raw()`, `having_raw()`, and raw statements as well; never interpolate user input into SQL.

## Transactions and replicas

```php
db::transaction(function ()
{
    db::table('account')->where('id', 1)->update(['balance' => db::raw('balance - 100')]);
    db::table('account')->where('id', 2)->update(['balance' => db::raw('balance + 100')]);
}, 3);
```

Ordinary reads may use a replica; `on_master()` forces the primary. `cursor()` streams rows and `chunk()` processes bounded batches. Connection configuration includes slow-query thresholds, reconnect policy, and an optional SELECT ceiling.
