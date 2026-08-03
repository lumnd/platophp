<?php
/**
 * plato\psr\cache: the PSR-16 adapter over plato\cache.
 *
 * Driven against the memory store, so none of this needs a server.
 */

use plato\cache\cache as store;
use plato\config;
use plato\plato;
use plato\psr\cache;
use plato\psr\invalid_argument;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

plato::registry(plato_test_config());

$original = null;

beforeEach(function () use (&$original) {
    $original = (string) config::instance('cache')->get('cache_type');

    store::configure(['cache_type' => 'memory']);
});

afterEach(function () use (&$original) {
    store::configure(['cache_type' => $original]);
});

it('is a psr-16 cache', function () {
    expect(new cache())->toBeInstanceOf(CacheInterface::class);
});

it('stores and returns a value', function () {
    $psr = new cache();

    expect($psr->set('platophptest_k', ['a' => 1]))->toBeTrue()
        ->and($psr->get('platophptest_k'))->toBe(['a' => 1]);
});

it('answers the default for a key it does not hold', function () {
    expect((new cache())->get('platophptest_absent', 'fallback'))->toBe('fallback');
});

it('deletes a key, and reports success for one that was not there', function () {
    $psr = new cache();
    $psr->set('platophptest_k', 1);

    expect($psr->delete('platophptest_k'))->toBeTrue()
        ->and($psr->has('platophptest_k'))->toBeFalse()
        ->and($psr->delete('platophptest_k'))->toBeTrue();
});

it('treats a lifetime that has already passed as a delete', function () {
    $psr = new cache();
    $psr->set('platophptest_k', 1);

    expect($psr->set('platophptest_k', 2, 0))->toBeTrue()
        ->and($psr->has('platophptest_k'))->toBeFalse();
});

it('takes a lifetime as seconds', function () {
    $psr = new cache();
    $psr->set('platophptest_k', 1, 30);

    expect(store::ttl('platophptest_k'))->toBe(30);
});

it('takes a lifetime as a DateInterval', function () {
    $psr = new cache();
    $psr->set('platophptest_k', 1, new DateInterval('PT90S'));

    expect(store::ttl('platophptest_k'))->toBe(90);
});

it('reads and writes several keys at once', function () {
    $psr = new cache();

    expect($psr->setMultiple(['platophptest_a' => 1, 'platophptest_b' => 2]))->toBeTrue();

    expect($psr->getMultiple(['platophptest_a', 'platophptest_b', 'platophptest_c'], 'none'))
        ->toBe([
            'platophptest_a' => 1,
            'platophptest_b' => 2,
            'platophptest_c' => 'none',
        ]);

    expect($psr->deleteMultiple(['platophptest_a', 'platophptest_b']))->toBeTrue()
        ->and($psr->has('platophptest_a'))->toBeFalse();
});

it('empties the store on clear', function () {
    $psr = new cache();
    $psr->set('platophptest_k', 1);

    expect($psr->clear())->toBeTrue()
        ->and($psr->has('platophptest_k'))->toBeFalse();
});

it('refuses an empty key', function () {
    expect(fn () => (new cache())->get(''))->toThrow(invalid_argument::class);
});

it('refuses a key holding a character psr-16 reserves', function () {
    foreach (['user:1', 'a{b}', 'a(b)', 'a/b', 'a\\b', 'a@b'] as $key)
    {
        expect(fn () => (new cache())->set($key, 1))->toThrow(invalid_argument::class);
    }
});

it('throws something a psr-16 caller can catch', function () {
    // The class is the framework's, but the interface is the one a library catching this knows.
    // expect()->toThrow() cannot be given an interface, so the check is by hand
    try
    {
        (new cache())->get('user:1');

        $caught = null;
    }
    catch (Throwable $e)
    {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class);
});

it('tells a stored falsy value apart from a miss, as psr-16 requires', function () {
    $psr = new cache();

    foreach ([false, 0, '', null] as $i => $value)
    {
        $key = 'platophptest_falsy_' . $i;

        expect($psr->set($key, $value))->toBeTrue()
            ->and($psr->get($key, 'default'))->toBe($value)
            ->and($psr->has($key))->toBeTrue();
    }

    expect($psr->get('platophptest_absent', 'default'))->toBe('default')
        ->and($psr->has('platophptest_absent'))->toBeFalse();
});
