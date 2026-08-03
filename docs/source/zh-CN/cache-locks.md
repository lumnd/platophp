# 缓存与锁

## Cache

```php
use plato\cache\cache;

cache::set('user:7', $user, 300);
$user = cache::get('user:7');
$exists = cache::has('user:7');

$user = cache::remember('user:7', 300, fn () => load_user(7));
cache::tags(['user', 'tenant:3'])->set('user:7', $user, 300);
cache::tags(['user'])->flush();
```

驱动包括 `redis`、`file`、`memcached` 和 `memory`。`memory` 只在当前进程内有效；文件驱动适合单机普通缓存；跨 worker 原子计数使用 Redis。

`get()` 的返回值不能单独说明 key 是否存在，因为 `false`、`0`、空字符串和空数组都是合法值；需要区分时调用 `has()`。

## PSR-16

安装 `psr/simple-cache` 后，可把 `plato\psr\cache` 交给只接受 `Psr\SimpleCache\CacheInterface` 的库。

## 分布式锁

```php
use plato\lock;

$result = lock::guard('invoice:42', function () {
    return issue_invoice(42);
}, timeout: 3, expire: 15);
```

`plato\lock` 只使用 Redis。锁值带持有者 token，解锁与续期通过原子脚本完成。锁服务器不能使用会任意淘汰锁 key 的 maxmemory policy。
