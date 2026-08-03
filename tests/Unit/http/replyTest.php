<?php

/**
 * reply: immutable response metadata and buffered access to streamed bodies.
 */

use plato\http\reply;

it('returns modified copies without changing the original', function () {
    $original = new reply(200, ['X-One' => '1'], 'ok');
    $changed  = $original->with_status(201)->with_header('X-Two', '2');

    expect($original->status())->toBe(200)
        ->and($original->headers())->toBe(['X-One' => '1'])
        ->and($changed->status())->toBe(201)
        ->and($changed->headers())->toBe(['X-One' => '1', 'X-Two' => '2']);
});

it('strips line breaks from added response headers', function () {
    $reply = (new reply())->with_header("X-Test\r\nInjected", "yes\r\nX-Evil: yes");

    expect($reply->headers())->toBe(['X-TestInjected' => 'yesX-Evil: yes']);
});

it('captures a streamed body once when an adapter reads it', function () {
    $calls = 0;
    $reply = new reply(200, [], function () use (&$calls) {
        $calls++;
        echo 'stream';
    });

    expect($reply->body())->toBe('stream')
        ->and($reply->body())->toBe('stream')
        ->and($calls)->toBe(1);
});

it('replaces the body without touching the original', function () {
    $original = new reply(200, ['X-One' => '1'], 'page');
    $changed  = $original->with_body('page + panel');

    expect($original->body())->toBe('page')
        ->and($changed->body())->toBe('page + panel')
        ->and($changed->status())->toBe(200)
        ->and($changed->headers())->toBe(['X-One' => '1']);
});

it('drops the writer when a streamed body is replaced', function () {
    $calls = 0;
    $reply = new reply(200, [], function () use (&$calls) {
        $calls++;
        echo 'stream';
    });

    // Read it first, which is what a middleware rewriting a body has to do anyway
    $replaced = $reply->with_body($reply->body() . ' + panel');

    ob_start();
    $replaced->emit_body();

    expect((string) ob_get_clean())->toBe('stream + panel')
        ->and($calls)->toBe(1);
});
