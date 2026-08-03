# 数据库

PlatoPHP 提供 MySQL/MariaDB、ClickHouse 和 MongoDB driver。连接按首次实际查询惰性创建，并由 `plato\runtime` 管理进程边界。

## 配置

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

## 查询构造器

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

值默认使用 prepared statement 绑定。`where_raw()`、`having_raw()` 和原生语句仍应通过 bindings 传值，不能把用户输入拼进 SQL。

## 事务与读写分离

```php
db::transaction(function ()
{
    db::table('account')->where('id', 1)->update(['balance' => db::raw('balance - 100')]);
    db::table('account')->where('id', 2)->update(['balance' => db::raw('balance + 100')]);
}, 3);
```

普通查询可走 read replica；`on_master()` 强制读主库。`cursor()` 流式读取，`chunk()` 分批处理。连接配置支持慢查询阈值、断线重试和 SELECT 上限。
