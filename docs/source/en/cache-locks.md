# Cache and Locks

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

Stores are `redis`, `file`, `memcached`, and `memory`. Memory is local to the current process; file is suitable for ordinary single-host caching; atomic counters across workers require Redis.

A `get()` result alone cannot prove a key exists because `false`, `0`, empty strings, and empty arrays are valid values. Use `has()` when the distinction matters.

## PSR-16

With `psr/simple-cache` installed, pass `plato\psr\cache` to libraries requiring `Psr\SimpleCache\CacheInterface`.

## Distributed locks

```php
use plato\lock;

$result = lock::guard('invoice:42', function () {
    return issue_invoice(42);
}, timeout: 3, expire: 15);
```

`plato\lock` uses Redis only. Values include holder tokens, and unlock or renewal operations use atomic scripts. The lock server must not use a maxmemory policy that can arbitrarily evict lock keys.
