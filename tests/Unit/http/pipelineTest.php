<?php
/**
 * plato\http\pipeline: which middleware applies to a route, and what running them does.
 *
 * The pipeline's place inside plato::run() is covered end to end in
 * tests/Feature/httpKernelTest.php, over a real socket.
 */

use plato\exception\route_exception;
use plato\http\pipeline;
use plato\plato;

plato::registry(plato_test_config());

/**
 * Middleware as a class with handle(), the form the make:middleware stub writes.
 */
class trace_middleware
{
    /** @var array<int, string> */
    public static $trace = [];

    public function handle(callable $next)
    {
        self::$trace[] = 'before:' . static::class;

        $result = $next();

        self::$trace[] = 'after:' . static::class;

        return $result;
    }
}

class second_trace_middleware extends trace_middleware
{
}

/**
 * Middleware that answers on its own, without calling $next.
 */
class stopping_middleware
{
    public function handle(callable $next)
    {
        return 'stopped';
    }
}

/**
 * Middleware as an invokable object.
 */
class invokable_middleware
{
    public function __invoke(callable $next)
    {
        return 'invoked:' . $next();
    }
}

/**
 * Not middleware at all: no handle(), not invokable.
 */
class useless_middleware
{
}

beforeEach(function () {
    trace_middleware::$trace = [];
    pipeline::reset();
});

afterEach(function () {
    pipeline::reset();
});

it('reads its map from config/config.php', function () {
    // The framework default is empty; what is here is the fixture application's own map, the one
    // tests/Feature/httpKernelTest.php drives over a real socket
    expect(pipeline::config())->toHaveKey('middleware:*')
        ->and(pipeline::config()['middleware:*'])->toBe(['middleware\marker']);
});

it('matches the three pattern forms', function () {
    expect(pipeline::matches('*', 'index', 'view'))->toBeTrue()
        ->and(pipeline::matches('index:*', 'index', 'view'))->toBeTrue()
        ->and(pipeline::matches('index:view', 'index', 'view'))->toBeTrue()
        ->and(pipeline::matches('index:edit', 'index', 'view'))->toBeFalse()
        ->and(pipeline::matches('admin:*', 'index', 'view'))->toBeFalse();
});

it('collects the middleware of every pattern that matches, global first', function () {
    pipeline::configure([
        'index:view' => ['c'],
        '*'          => ['a'],
        'index:*'    => ['b'],
    ]);

    // The order is the order of the map, which is the order of the configuration file
    expect(pipeline::for_route('index', 'view'))->toBe(['c', 'a', 'b']);
    expect(pipeline::for_route('index', 'edit'))->toBe(['a', 'b']);
    expect(pipeline::for_route('admin', 'edit'))->toBe(['a']);
});

it('runs a middleware named by two patterns once', function () {
    pipeline::configure([
        '*'       => ['a', 'b'],
        'index:*' => ['a'],
    ]);

    expect(pipeline::for_route('index', 'view'))->toBe(['a', 'b']);
});

it('runs the destination when there is no middleware', function () {
    expect(pipeline::run([], fn () => 'destination'))->toBe('destination');
});

it('wraps the destination, outermost first', function () {
    $result = pipeline::run(
        [trace_middleware::class, second_trace_middleware::class],
        function () {
            trace_middleware::$trace[] = 'action';

            return 'done';
        }
    );

    expect($result)->toBe('done')
        ->and(trace_middleware::$trace)->toBe([
            'before:trace_middleware',
            'before:second_trace_middleware',
            'action',
            'after:second_trace_middleware',
            'after:trace_middleware',
        ]);
});

it('stops the request when a middleware does not call next', function () {
    $reached = false;

    $result = pipeline::run(
        [stopping_middleware::class, trace_middleware::class],
        function () use (&$reached) {
            $reached = true;
        }
    );

    expect($result)->toBe('stopped')
        ->and($reached)->toBeFalse()
        ->and(trace_middleware::$trace)->toBeEmpty();
});

it('accepts a closure', function () {
    $result = pipeline::run(
        [function (callable $next) {
            return 'closure:' . $next();
        }],
        fn () => 'action'
    );

    expect($result)->toBe('closure:action');
});

it('accepts an invokable class', function () {
    expect(pipeline::run([invokable_middleware::class], fn () => 'action'))->toBe('invoked:action');
});

it('accepts a callable array', function () {
    $result = pipeline::run(
        [[new invokable_middleware(), '__invoke']],
        fn () => 'action'
    );

    expect($result)->toBe('invoked:action');
});

it('refuses a class that is not middleware', function () {
    expect(fn () => pipeline::run([useless_middleware::class], fn () => 'action'))
        ->toThrow(route_exception::class);
});

it('refuses an entry that is not runnable at all', function () {
    expect(fn () => pipeline::run(['no\such\middleware'], fn () => 'action'))
        ->toThrow(route_exception::class);
});
