<?php

/**
 * msgbox: escaped, self-contained HTML responses and local redirects.
 */

use plato\http\resp;
use plato\view\msgbox;

beforeEach(function () {
    resp::reset();
});

it('escapes message content and accepts a local redirect', function () {
    $reply = msgbox::show('<b>Title</b>', '<script>alert(1)</script>', '/orders', 1500);

    expect($reply->body())->toContain('&lt;b&gt;Title&lt;/b&gt;')
        ->and($reply->body())->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($reply->body())->toContain('content="2;url=/orders"');
});

it('drops external and script redirect targets', function (string $url) {
    $body = msgbox::show('Title', 'Message', $url)->body();

    expect($body)->not->toContain($url);
})->with([
    'external' => 'https://evil.example',
    'script'   => 'javascript:alert(1)',
    'network'  => '//evil.example',
]);

it('returns the requested error status without exiting', function () {
    $reply = msgbox::error(500);

    expect($reply->status())->toBe(500)
        ->and($reply->body())->toContain('Internal Server Error');
});
