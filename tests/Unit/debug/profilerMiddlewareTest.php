<?php

/**
 * plato\debug\profiler_middleware: reply decoration and payload boundaries.
 */

use plato\debug\profiler;
use plato\debug\profiler_middleware;
use plato\http\reply;
use plato\http\resp;
use plato\plato;

beforeEach(function () {
    profiler::reset();
    resp::reset();
});

afterEach(function () {
    profiler::reset();
    resp::reset();
});

it('decorates a completed html reply in debug mode', function () {
    $middleware = new profiler_middleware();
    $reply      = $middleware->handle(static fn () => new reply(
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
        '<html><body>page</body></html>'
    ));

    expect($reply)->toBeInstanceOf(reply::class)
        ->and($reply->body())->toContain('id="plato_profiler"')
        ->and($reply->body())->toEndWith('</body></html>');
});

it('decorates a reply the action prepared instead of returning', function () {
    // plato::run() answers this form from resp::prepared(), so the panel must not depend on
    // whether the action wrote `return resp::html(...)` or just `resp::html(...)`
    $reply = (new profiler_middleware())->handle(static function () {
        resp::html('<html><body>page</body></html>');
    });

    expect($reply)->toBeInstanceOf(reply::class)
        ->and($reply->body())->toContain('id="plato_profiler"')
        ->and($reply->body())->toEndWith('</body></html>');
});

it('hands back a prepared non-html reply without decorating it', function () {
    $reply = (new profiler_middleware())->handle(static function () {
        resp::text('plain');
    });

    expect($reply)->toBeInstanceOf(reply::class)
        ->and($reply->body())->toBe('plain');
});

it('does not decorate replies when debug mode is off', function () {
    $previous = plato::$config['debug'] ?? null;
    plato::$config['debug'] = false;

    try
    {
        $reply = (new profiler_middleware())->handle(static fn () => new reply(
            200,
            ['Content-Type' => 'text/html'],
            '<html><body>page</body></html>'
        ));

        expect($reply->body())->toBe('<html><body>page</body></html>')
            ->and(profiler::instance()->enable_profiler)->toBeFalse();
    }
    finally
    {
        plato::$config['debug'] = $previous;
    }
});

it('does not buffer a non-html writer reply', function () {
    $writes = 0;
    $source = new reply(200, ['Content-Type' => 'application/octet-stream'], function () use (&$writes): void {
        $writes++;
        echo 'file';
    });

    $reply = (new profiler_middleware())->handle(static fn () => $source);

    expect($reply)->toBe($source)
        ->and($writes)->toBe(0);
});

it('passes non-reply pipeline results through unchanged', function () {
    expect((new profiler_middleware())->handle(static fn () => 'value'))->toBe('value');
});
