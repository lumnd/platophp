<?php

/**
 * plato\cache\cache: the facade, driven against the memory store.
 *
 * The redis backed run of the same behaviour is tests/Feature/cacheTest.php. What is asserted here
 * is the facade's own work -- key namespacing, the memoized copy, free() and free_mem() -- which
 * does not need a server to be true.
 */

use plato\cache\cache;
use plato\cache\memory;
use plato\cache\repository;
use plato\config;
use plato\plato;
use plato\runtime;

plato::registry(plato_test_config());

/*
 * The facade reads its configuration once and keeps the driver in plato\runtime, so the store is
 * swapped by naming it and dropping whatever driver the previous test left behind. $cache_type is
 * restored afterwards: the suite runs in one process and tests/Feature/cacheTest.php expects the
 * configured store.
 */
$original = null;

beforeEach(function () use (&$original) {
    // The configured store, read from the configuration rather than off the facade: the property
    // only holds it once the facade has booted, and booting it here would connect to whatever the
    // configuration names -- the very thing this file is meant not to need
    $original = (string) config::instance('cache')->get('cache_type');

    cache::configure(['cache_type' => 'memory']);
});

afterEach(function () use (&$original) {
    cache::configure(['cache_type' => $original]);
});

it('builds the store the configuration names', function () {
    cache::set('platophptest_k', 1);

    expect(runtime::get('cache.repository')->store())->toBeInstanceOf(memory::class);
});

it('stores and returns a scalar', function () {
    expect(cache::set('platophptest_k', 10))->toBeTrue();
    expect(cache::get('platophptest_k'))->toBe(10);
});

it('stores and returns an array', function () {
    $value = ['aa' => 1, 'bb' => 'ssss'];

    cache::set('platophptest_k', $value);

    expect(cache::get('platophptest_k'))->toBe($value);
});

it('stores a null value like any other', function () {
    expect(cache::set('platophptest_k', null))->toBeTrue();
    expect(cache::get('platophptest_k', 'default'))->toBeNull();
    expect(cache::has('platophptest_k'))->toBeTrue();
});

it('forgets a key on del', function () {
    cache::set('platophptest_k', 10);

    expect(cache::del('platophptest_k'))->toBe(1);
    expect(cache::has('platophptest_k'))->toBeFalse();
});

it('keeps a stored falsy value apart from a miss', function () {
    foreach ( [false, 0, '', []] as $i => $value )
    {
        $key = 'platophptest_falsy_' . $i;

        cache::set($key, $value);

        expect(cache::get($key, 'default'))->toBe($value);
        expect(cache::has($key))->toBeTrue();
    }

    expect(cache::get('platophptest_absent', 'default'))->toBe('default');
    expect(cache::has('platophptest_absent'))->toBeFalse();
});

it('answers a miss with the default it was given', function () {
    $sentinel = new stdClass();

    cache::set('platophptest_k', false);

    // What code that has to tell a cached false from a miss does: the sentinel is an object, so
    // nothing that can be stored is identical to it
    expect(cache::get('platophptest_k', $sentinel))->toBeFalse()
        ->and(cache::get('platophptest_gone', $sentinel))->toBe($sentinel);
});

it('keeps the ttl it was given', function () {
    cache::set('platophptest_k', ['aa' => 1], 10);

    expect(cache::ttl('platophptest_k'))->toBe(10);
});

it('answers -2 for the ttl of a key it does not hold', function () {
    expect(cache::ttl('platophptest_absent'))->toBe(-2);
});

it('counts up and down', function () {
    expect(cache::inc('platophptest_n'))->toBe(1);
    expect(cache::inc('platophptest_n'))->toBe(2);
    expect(cache::get('platophptest_n'))->toBe(2);
    expect(cache::dec('platophptest_n'))->toBe(1);
});

it('namespaces every key it passes to the store', function () {
    cache::set('platophptest_k', 'value');

    // The store never sees the key the caller used; it sees the digest the facade builds out of
    // the configured prefix, which is what keeps two projects on one server apart
    expect(runtime::get('cache.repository')->store()->get('platophptest_k'))->toBeFalse();
});

it('drops the driver on free, and rebuilds it on the next call', function () {
    cache::set('platophptest_k', 'value');

    expect(cache::free())->toBeTrue();
    expect(runtime::get('cache.repository'))->toBeNull();
    // A memory store is gone with its driver, so this reads as a miss rather than as a stale hit
    expect(cache::get('platophptest_k'))->toBeFalse();
});

it('clears the store on free_mem when asked to', function () {
    cache::set('platophptest_k', 'value');

    expect(cache::free_mem(true))->toBeTrue();
    expect(cache::get('platophptest_k'))->toBeFalse();
});

it('produces and stores a value remember() finds missing', function () {
    $calls = 0;

    $first  = cache::remember('platophptest_r', 60, function () use (&$calls) {
        $calls++;

        return ['built' => true];
    });
    $second = cache::remember('platophptest_r', 60, function () use (&$calls) {
        $calls++;

        return ['built' => 'again'];
    });

    expect($first)->toBe(['built' => true])
        ->and($second)->toBe(['built' => true])
        ->and($calls)->toBe(1);
});

it('stores a producer that answers null or false, and does not run it twice', function () {
    $calls = 0;

    $producer = function () use (&$calls) {
        $calls++;

        return null;
    };

    expect(cache::remember('platophptest_null', 60, $producer))->toBeNull()
        ->and(cache::remember('platophptest_null', 60, $producer))->toBeNull()
        ->and($calls)->toBe(1);

    // "there is no such thing" is worth remembering too, so it reached the store
    expect(cache::ttl('platophptest_null'))->toBe(60)
        ->and(cache::has('platophptest_null'))->toBeTrue();

    expect(cache::remember('platophptest_false', 60, fn () => false))->toBeFalse()
        ->and(cache::remember('platophptest_false', 60, fn () => 'not asked'))->toBeFalse();
});

it('keeps a remember_forever value without an expiry', function () {
    cache::remember_forever('platophptest_f', fn () => 'value');

    expect(cache::get('platophptest_f'))->toBe('value')
        ->and(cache::ttl('platophptest_f'))->toBe(-1);
});

it('exposes driver specific operations explicitly through the repository store', function () {
    cache::set('platophptest_k', 'value');

    expect(cache::repository()->store()->flush())->toBeTrue();
    expect(cache::get('platophptest_k'))->toBeFalse();
});

it('keeps directly constructed repositories independent', function () {
    $first  = new repository(new memory(), 'first', 60);
    $second = new repository(new memory(), 'second', 60);

    $first->set('same-key', 'first');
    $second->set('same-key', 'second');

    expect($first->get('same-key'))->toBe('first')
        ->and($second->get('same-key'))->toBe('second');
});
