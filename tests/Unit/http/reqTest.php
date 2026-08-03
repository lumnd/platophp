<?php

/**
 * req: request-scoped state and its reset boundary.
 */

use plato\http\req;

beforeEach(function () {
    req::reset_input();
});

afterEach(function () {
    req::reset_input();
});

it('forgets cookies with the rest of the request input', function () {
    req::$cookies = ['sid' => 'old'];

    req::reset_input();

    expect(req::cookie())->toBe([]);
});

it('reports the request path and the page to return to', function () {
    $server = $_SERVER;

    try
    {
        $_SERVER['REQUEST_URI'] = '/orders?page=2';
        $_SERVER['HTTP_REFERER'] = 'https://example.test/account';

        expect(req::path())->toBe('/orders?page=2')
            ->and(req::back_url('/fallback'))->toBe('https://example.test/account');

        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER']);
        $_SERVER['PHP_SELF']    = '/index.php';
        $_SERVER['QUERY_STRING'] = 'ct=order&ac=list';

        expect(req::path())->toBe('/index.php?ct=order&ac=list')
            ->and(req::back_url('/fallback'))->toBe('/fallback')
            ->and(method_exists(req::class, 'cururl'))->toBeFalse()
            ->and(method_exists(req::class, 'forword'))->toBeFalse();
    }
    finally
    {
        $_SERVER = $server;
    }
});

it('accepts a server country code but never a client supplied country header', function () {
    $server = $_SERVER;

    try
    {
        $_SERVER['COUNTRY_SHORT']              = 'tw';
        $_SERVER['HTTP_X_REAL_COUNTRY_SHORT'] = 'US';

        expect(req::country())->toBe('TW');

        unset($_SERVER['COUNTRY_SHORT']);

        expect(req::country())->toBe('-');
    }
    finally
    {
        $_SERVER = $server;
    }
});

it('rejects a malformed server country code', function () {
    $server = $_SERVER;

    try
    {
        $_SERVER['COUNTRY_SHORT'] = '../TW';

        expect(req::country())->toBe('-');
    }
    finally
    {
        $_SERVER = $server;
    }
});
