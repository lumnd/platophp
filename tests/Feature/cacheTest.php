<?php
/**
 * cache: the store configured for the test app.
 *
 * This needs a reachable Redis. A failure here is an environment problem, not a broken
 * assertion -- do not weaken the test to make it pass without the service.
 */

use plato\cache\cache;

afterEach(function () {
    cache::del('platophptest_cache');
    cache::del('platophptest_counter');
});

it('stores and returns a scalar', function () {
    expect(cache::set('platophptest_cache', 10))->toBeTrue();
    expect(cache::get('platophptest_cache'))->toBe(10);
});

it('stores and returns an array', function () {
    $value = ['aa' => 1, 'bb' => 'ssss'];

    expect(cache::set('platophptest_cache', $value))->toBeTrue();
    expect(cache::get('platophptest_cache'))->toBe($value);
});

it('forgets a key on del', function () {
    cache::set('platophptest_cache', 10);

    expect(cache::del('platophptest_cache'))->not->toBeEmpty();
    expect(cache::has('platophptest_cache'))->toBeFalse();
});

it('keeps the ttl it was given', function () {
    cache::set('platophptest_cache', ['aa' => 1], 10);

    expect(cache::ttl('platophptest_cache'))->toBe(10);
});

it('increments and decrements a counter', function () {
    expect(cache::inc('platophptest_counter', 1))->toBe(1);
    expect(cache::inc('platophptest_counter', 1))->toBe(2);
    expect(cache::get('platophptest_counter'))->toBe(2);

    expect(cache::dec('platophptest_counter', 1))->toBe(1);
    expect(cache::get('platophptest_counter'))->toBe(1);
});
