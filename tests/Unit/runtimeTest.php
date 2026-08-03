<?php
/**
 * plato\runtime, the registry of resources that belong to one process.
 *
 * Nothing here forks: the fork behaviour needs real processes and lives in
 * tests/Feature/forkSafetyTest.php. What this file covers is the registry itself.
 */

use plato\runtime;

/**
 * A key nothing else in the suite can collide with.
 */
function runtime_test_key(string $name): string
{
    return 'test.runtime.' . $name;
}

afterEach(function () {
    foreach ( runtime::keys() as $key )
    {
        if ( strpos($key, 'test.runtime.') === 0 )
        {
            runtime::forget($key);
        }
    }
});

it('builds a resource once and hands the same one back', function () {
    $key   = runtime_test_key('once');
    $built = 0;

    $factory = function () use (&$built)
    {
        $built++;

        return new stdClass();
    };

    $first  = runtime::share($key, $factory);
    $second = runtime::share($key, $factory);

    expect($built)->toBe(1);
    expect($second)->toBe($first);
});

it('registers nothing when the factory throws, so the next call tries again', function () {
    $key   = runtime_test_key('throws');
    $calls = 0;

    $factory = function () use (&$calls)
    {
        $calls++;

        if ( $calls === 1 )
        {
            throw new RuntimeException('not this time');
        }

        return 'built';
    };

    expect(fn () => runtime::share($key, $factory))->toThrow(RuntimeException::class);
    expect(runtime::has($key))->toBeFalse();

    expect(runtime::share($key, $factory))->toBe('built');
    expect($calls)->toBe(2);
});

it('reads a resource without building it', function () {
    $key = runtime_test_key('peek');

    expect(runtime::get($key))->toBeNull();
    expect(runtime::get($key, 'fallback'))->toBe('fallback');
    expect(runtime::has($key))->toBeFalse();

    runtime::share($key, fn () => 'built');

    expect(runtime::get($key))->toBe('built');
});

it('runs the closer on forget and drops the key first', function () {
    $key    = runtime_test_key('closed');
    $closed = [];

    runtime::share($key, fn () => 'resource', function ($value) use (&$closed, $key)
    {
        // The key has to be gone by now: a closer reaching back in -- a destructor calling
        // forget() again -- must not find it and loop
        $closed[] = [$value, runtime::has($key)];
    });

    expect(runtime::forget($key))->toBeTrue();
    expect($closed)->toBe([['resource', false]]);
    expect(runtime::has($key))->toBeFalse();

    // Forgetting twice is not an error, and does not run the closer again
    expect(runtime::forget($key))->toBeFalse();
    expect($closed)->toHaveCount(1);
});

it('keeps releasing the rest of the map when one closer throws', function () {
    $released = [];

    runtime::share(runtime_test_key('a'), fn () => 'a', function () use (&$released)
    {
        $released[] = 'a';
    });

    runtime::share(runtime_test_key('boom'), fn () => 'boom', function ()
    {
        throw new RuntimeException('teardown failed');
    });

    runtime::share(runtime_test_key('b'), fn () => 'b', function () use (&$released)
    {
        $released[] = 'b';
    });

    runtime::flush();

    // Reverse order, so something registered on top of another resource goes first
    expect($released)->toBe(['b', 'a']);
    expect(runtime::has(runtime_test_key('a')))->toBeFalse();
    expect(runtime::has(runtime_test_key('b')))->toBeFalse();
});

it('reports the pid it belongs to and an epoch that does not move on its own', function () {
    $epoch = runtime::epoch();

    expect(runtime::pid())->toBe((int) getmypid());

    runtime::share(runtime_test_key('epoch'), fn () => 'x');
    runtime::forget(runtime_test_key('epoch'));

    // Only a fork moves it, and nothing here forked
    expect(runtime::epoch())->toBe($epoch);
    expect(runtime::forked())->toBeFalse();
});

it('unregisters a fork listener', function () {
    $handle = runtime::on_fork(function () {});

    expect(runtime::off_fork($handle))->toBeTrue();
    expect(runtime::off_fork($handle))->toBeFalse();
});

it('runs a deferred callback straight away under cli', function () {
    // There is no connection to release in a console process, so nothing is queued
    expect(runtime::shutdown_function('strtoupper', ['plato']))->toBe('PLATO');
});
