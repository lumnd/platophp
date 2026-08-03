<?php
/**
 * plato\security\throttle: the fixed window counter.
 *
 * Driven against the memory store, so nothing here needs redis. What the store does change is
 * atomicity, not arithmetic, and arithmetic is what this file asserts.
 */

use plato\cache\cache;
use plato\config;
use plato\http\pipeline;
use plato\http\resp;
use plato\plato;
use plato\security\throttle;

plato::registry(plato_test_config());

$original = null;

beforeEach(function () use (&$original) {
    $original = (string) config::instance('cache')->get('cache_type');

    cache::configure(['cache_type' => 'memory']);
    throttle::reset();
    // The refusal is written through resp, so the queued status and the encryption flag have to
    // start clean whatever the file that ran before this one left behind
    resp::reset();
});

afterEach(function () use (&$original) {
    cache::configure(['cache_type' => $original]);
    throttle::reset();
    resp::reset();
});

it('lets a caller through up to the limit and no further', function () {
    expect(throttle::attempt('k', 3, 60))->toBeTrue()
        ->and(throttle::attempt('k', 3, 60))->toBeTrue()
        ->and(throttle::attempt('k', 3, 60))->toBeTrue()
        ->and(throttle::attempt('k', 3, 60))->toBeFalse();
});

it('keeps counting an attempt that was refused', function () {
    // Hammering a closed door keeps it closed rather than resetting the count
    throttle::attempt('k', 1, 60);
    throttle::attempt('k', 1, 60);
    throttle::attempt('k', 1, 60);

    expect(throttle::hits('k', 60))->toBe(3);
});

it('counts each key on its own', function () {
    throttle::attempt('a', 1, 60);

    expect(throttle::attempt('b', 1, 60))->toBeTrue();
});

it('treats a limit of zero as no limit at all', function () {
    expect(throttle::attempt('k', 0, 60))->toBeTrue()
        ->and(throttle::attempt('k', 0, 60))->toBeTrue();

    // Nothing was counted either: a call that cannot be refused has nothing to record
    expect(throttle::hits('k', 60))->toBe(0);
});

it('reports what is left of the allowance', function () {
    throttle::attempt('k', 5, 60);
    throttle::attempt('k', 5, 60);

    expect(throttle::remaining('k', 5, 60))->toBe(3);
});

it('never reports a negative remainder', function () {
    foreach ( range(1, 5) as $ignored )
    {
        throttle::attempt('k', 2, 60);
    }

    expect(throttle::remaining('k', 2, 60))->toBe(0);
});

it('gives the counter a lifetime on its first hit', function () {
    throttle::attempt('k', 5, 60);

    // Without this the slot of every caller and every window would stay in the store for good
    $ttl = cache::ttl('throttle|k|' . (int) floor(time() / 60));

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('counts a new window separately', function () {
    // A window of one second, so the slot index moves on while the test is running. Both of the
    // waits below are on the clock rather than on sleep(1): the two attempts have to land in the
    // same second to be the same window, and sleep() may return early when a signal arrives --
    // either of which made this case fail for a reason that had nothing to do with throttling
    $key = 'k' . getmypid();

    $second = time();
    while ( time() === $second && (int) (microtime(true) * 1000) % 1000 > 700 )
    {
        usleep(50000);
    }

    $second = time();

    throttle::attempt($key, 1, 1);
    expect(throttle::attempt($key, 1, 1))->toBeFalse();

    while ( time() === $second )
    {
        usleep(20000);
    }

    expect(throttle::attempt($key, 1, 1))->toBeTrue();
});

it('answers a retry-after inside the window and never zero', function () {
    $retry = throttle::retry_after(60);

    expect($retry)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
});

it('forgets a count on clear', function () {
    throttle::attempt('k', 1, 60);

    throttle::clear('k', 60);

    expect(throttle::hits('k', 60))->toBe(0)
        ->and(throttle::attempt('k', 1, 60))->toBeTrue();
});

it('lets a request through as middleware while the caller is under the limit', function () {
    throttle::configure(['limit' => 5, 'window' => 60, 'by' => fn () => 'middleware-under']);

    $reached = false;
    pipeline::run([throttle::class], function () use (&$reached) {
        $reached = true;

        return 'action';
    });

    expect($reached)->toBeTrue();
});

it('stops the request without reaching the action once the limit is spent', function () {
    throttle::configure(['limit' => 1, 'window' => 60, 'by' => fn () => 'middleware-over']);

    $reached = 0;
    $action  = function () use (&$reached) {
        $reached++;

        return 'action';
    };

    pipeline::run([throttle::class], $action);

    $reply = pipeline::run([throttle::class], $action);

    // The second request never got past the middleware
    expect($reached)->toBe(1)
        ->and($reply)->toBeInstanceOf(\plato\http\reply::class)
        ->and($reply->body())->toContain('"code":429')
        ->and($reply->body())->toContain('retry_after');
});

it('hands a callable message the seconds left instead of writing a response', function () {
    throttle::configure([
        'limit'   => 1,
        'window'  => 60,
        'by'      => fn () => 'middleware-message',
        'message' => fn (int $retry_after) => 'retry in ' . $retry_after,
    ]);

    $action = fn () => 'action';

    pipeline::run([throttle::class], $action);
    $answer = pipeline::run([throttle::class], $action);

    expect($answer)->toStartWith('retry in ');
});

it('does nothing at all when it is switched off', function () {
    throttle::configure(['enable' => false, 'limit' => 1, 'window' => 60, 'by' => fn () => 'off']);

    $action = fn () => 'action';

    expect(pipeline::run([throttle::class], $action))->toBe('action')
        ->and(pipeline::run([throttle::class], $action))->toBe('action');
});
