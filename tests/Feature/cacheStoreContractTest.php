<?php
/**
 * plato\cache\store: the same contract, driven against every driver that ships with the package.
 *
 * The point of this file is uniformity. The facade holds no per driver branch, so a caller that
 * moves from `cache_type = memory` to `redis` must not have to change anything. Every case below
 * therefore runs on all four, including misses beside stored `false`, `0`, `''`, and `null`.
 *
 * redis and memcached need their service. A failure to connect is an environment problem, not a
 * broken assertion -- do not weaken a case to make it pass without the server. A missing extension
 * is a different thing and is skipped, because the package does not require either of them.
 */

use plato\cache\file;
use plato\cache\memcached;
use plato\cache\memory;
use plato\cache\redis;
use plato\cache\store;
use plato\plato;

plato::registry(plato_test_config());

/**
 * A setting, from the environment of the process or from the .env the fixture app loaded.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function store_test_env(string $key, $default)
{
    $value = $_ENV[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? $default : $value;
}

/**
 * The drivers to run the contract against, each with the reason it may be absent.
 *
 * @return array<string, callable(): store>
 */
function store_test_drivers(): array
{
    return [
        'memory' => fn (): store => new memory(),

        'file' => fn (): store => new file(
            rtrim(sys_get_temp_dir(), '/') . '/platophp_store_contract_' . getmypid()
        ),

        'redis' => function (): store {
            if (!class_exists('\Redis'))
            {
                test()->markTestSkipped('ext-redis is not installed');
            }

            return new redis('store_contract', [
                'host'       => store_test_env('REDIS_HOST', '127.0.0.1'),
                'port'       => (int) store_test_env('REDIS_PORT', 6379),
                'pass'       => store_test_env('REDIS_PASSWORD', ''),
                'keep-alive' => false,
                'timeout'    => 5,
                'dbindex'    => 6,
                'prefix'     => 'platophp_contract:',
                'serializer' => 'json',
            ]);
        },

        'memcached' => function (): store {
            if (!class_exists('\Memcached'))
            {
                test()->markTestSkipped('ext-memcached is not installed');
            }

            return new memcached([
                'servers' => [[
                    'host' => store_test_env('MEMCACHE_HOST', '127.0.0.1'),
                    'port' => (int) store_test_env('MEMCACHE_PORT', 11211),
                ]],
            ]);
        },
    ];
}

/**
 * A key nothing else in the suite uses.
 *
 * @param string $driver
 * @param string $name
 * @return string
 */
function store_test_key(string $driver, string $name): string
{
    return 'platophp_contract_' . $driver . '_' . getmypid() . '_' . $name;
}

dataset('stores', store_test_drivers());

it('tells a stored falsy value apart from a missing key', function (callable $build) {
    $store  = $build();
    $driver = basename(str_replace('\\', '/', get_class($store)));

    foreach ([false, 0, '', [], null] as $i => $value)
    {
        $key = store_test_key($driver, 'falsy_' . $i);

        expect($store->set($key, $value, 60))->toBeTrue()
            ->and($store->get($key, 'default'))->toBe($value)
            ->and($store->has($key))->toBeTrue();

        $store->del($key);
    }
})->with('stores');

it('answers a missing key with the default it was given', function (callable $build) {
    $store  = $build();
    $driver = basename(str_replace('\\', '/', get_class($store)));
    $key    = store_test_key($driver, 'absent');

    $store->del($key);

    $sentinel = new stdClass();

    expect($store->get($key))->toBeFalse()
        ->and($store->get($key, $sentinel))->toBe($sentinel)
        ->and($store->has($key))->toBeFalse();
})->with('stores');

it('stops reporting a key it was told to delete', function (callable $build) {
    $store  = $build();
    $driver = basename(str_replace('\\', '/', get_class($store)));
    $key    = store_test_key($driver, 'deleted');

    $store->set($key, false, 60);

    expect($store->has($key))->toBeTrue();
    expect($store->del($key))->toBe(1);
    expect($store->has($key))->toBeFalse()
        ->and($store->get($key, 'default'))->toBe('default');
})->with('stores');

afterAll(function () {
    $path = rtrim(sys_get_temp_dir(), '/') . '/platophp_store_contract_' . getmypid() . '.php';

    is_file($path) && unlink($path);
});
