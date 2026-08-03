<?php

/**
 * security: the CSRF defences -- where the token is read from, and the origin check that backs
 * it up when the client side encryption key is public.
 */

use plato\http\envelope;
use plato\http\req;
use plato\http\route;
use plato\plato;
use plato\security\crypt;
use plato\security\security;

beforeEach(function () {
    plato::$config['cli_csrf'] = true;
    route::reset(true);
    envelope::reset(true);
    req::reset_input();
    security::reset();

    $_SERVER['HTTP_HOST'] = 'example.com';
    unset(
        $_SERVER['HTTPS'],
        $_SERVER['SERVER_PORT'],
        $_SERVER['HTTP_X_FORWARDED_PROTO'],
        $_SERVER['HTTP_X_FORWARDED_PORT'],
        $_SERVER['HTTP_ORIGIN'],
        $_SERVER['HTTP_REFERER'],
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'],
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'],
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_X_CLIENT']
    );
});

afterEach(function () {
    plato::$config['cli_csrf'] = false;
    req::reset_config();
    security::reset();
    security::capture();
});

/**
 * Signed CSRF settings for one test identity.
 *
 * @param string $binding
 * @return array<string, mixed>
 */
function csrf_test_config(string $binding = 'session-a'): array
{
    return [
        'csrf_token_on' => true,
        'csrf_secret'   => str_repeat('s', 32),
        'csrf_binding'  => static fn(): string => $binding,
    ];
}

/*
|--------------------------------------------------------------------------
| CORS preflight
|--------------------------------------------------------------------------
*/

it('recognizes a browser preflight and returns its requested method', function () {
    route::assign('article', 'save', 'OPTIONS');
    $_SERVER['HTTP_ORIGIN']                        = 'https://app.example.com';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'post';

    expect(security::preflight_method())->toBe('POST');
});

it('keeps an ordinary options request out of the automatic preflight path', function () {
    route::assign('article', 'save', 'OPTIONS');

    expect(security::preflight_method())->toBeNull();
});

it('lets the application disable automatic preflight handling', function () {
    security::configure(['cors' => ['preflight' => false]]);
    route::assign('article', 'save', 'OPTIONS');
    $_SERVER['HTTP_ORIGIN']                        = 'https://app.example.com';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';

    expect(security::preflight_method())->toBeNull();
});

it('keeps an unsupported preflight method for action binding to refuse', function ($requested) {
    route::assign('article', 'save', 'OPTIONS');
    $_SERVER['HTTP_ORIGIN']                        = 'https://app.example.com';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = $requested;

    expect(security::preflight_method())->toBe($requested);
})->with([
    // check_action() stands method binding aside for the router's CLI marker, so a preflight
    // that could name it would be told a bound write action welcomes it
    'the CLI marker' => ['CLI'],
    'an invention'   => ['TRACE'],
]);

it('marks a malformed preflight method for action binding to refuse', function ($requested) {
    route::assign('article', 'save', 'OPTIONS');
    $_SERVER['HTTP_ORIGIN']                        = 'https://app.example.com';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = $requested;

    expect(security::preflight_method())->toBe('');
})->with([
    'a header split' => ["POST\r\nX-Injected: 1"],
    'nothing at all' => [''],
]);

it('echoes the requested headers back when none are configured', function () {
    // The test app names a list of its own, so this has to put the framework default back
    security::configure(['cors' => ['allow_headers' => null]]);
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'Content-Type, X-Client';

    expect(security::preflight_headers())->toBe('Content-Type, X-Client');
});

it('approves nothing when a requested header is outside the configured list', function () {
    security::configure(['cors' => ['allow_headers' => ['content-type']]]);
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'Content-Type, X-Client';

    // Not the subset: approving part of the list lets the browser send a request the server
    // never agreed to, while approving none is the failure CORS is built around
    expect(security::preflight_headers())->toBeNull();
});

it('fails closed when the configured header list is not an array of tokens', function ($allowed) {
    security::configure(['cors' => ['allow_headers' => $allowed]]);
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'Content-Type';

    expect(security::preflight_headers())->toBeNull();
})->with([
    'a string'       => ['Content-Type'],
    'a non string'   => [[200]],
    'an invalid name' => [["Content-Type\r\nX-Injected"]],
]);

it('matches configured request headers without regard to case', function () {
    security::configure(['cors' => ['allow_headers' => ['Content-Type', 'X-Client']]]);
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'content-type , X-CLIENT';

    expect(security::preflight_headers())->toBe('content-type , X-CLIENT');
});

it('refuses a requested header list that is not a token list', function () {
    security::configure(['cors' => ['allow_headers' => null]]);
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = "Content-Type\r\nX-Injected: 1";

    expect(security::preflight_headers())->toBeNull();
});

it('reports an empty request header list as nothing to approve', function () {
    expect(security::preflight_headers())->toBe('');
});

/*
|--------------------------------------------------------------------------
| Origin checking
|
| Origin is the part of a cross site request the attacker does not control:
| page script cannot set it. That makes it the only defence left standing
| when the client side encryption key is public, which it is on the web.
|--------------------------------------------------------------------------
*/

it('allows a same origin request', function () {
    $_SERVER['HTTP_ORIGIN'] = 'http://example.com';

    expect(security::origin_allowed())->toBeTrue();
});

it('refuses a cross origin request', function () {
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

    expect(security::origin_allowed())->toBeFalse();
});

it('refuses an origin that only looks like the host', function () {
    $_SERVER['HTTP_ORIGIN'] = 'http://example.com.evil.example';

    expect(security::origin_allowed())->toBeFalse();
});

it('refuses a scheme downgrade', function () {
    $_SERVER['HTTPS']       = 'on';
    $_SERVER['HTTP_ORIGIN'] = 'http://example.com';

    expect(security::origin_allowed())->toBeFalse();
});

it('allows a configured trusted origin', function () {
    req::configure(['csrf_trusted_origins' => ['https://app.example.com']]);
    $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';

    expect(security::origin_allowed())->toBeTrue();

    req::configure(['csrf_trusted_origins' => []]);
});

it('compares origins case insensitively', function () {
    $_SERVER['HTTP_ORIGIN'] = 'HTTP://EXAMPLE.COM';

    expect(security::origin_allowed())->toBeTrue();
});

it('falls back to the referer when there is no origin', function () {
    $_SERVER['HTTP_REFERER'] = 'http://example.com/some/page?a=1';

    expect(security::origin_allowed())->toBeTrue();
});

it('refuses a cross origin referer', function () {
    $_SERVER['HTTP_REFERER'] = 'https://evil.example/page';

    expect(security::origin_allowed())->toBeFalse();
});

it('refuses a referer it cannot parse an origin out of', function () {
    $_SERVER['HTTP_REFERER'] = 'not a url';

    expect(security::origin_allowed())->toBeFalse();
});

it('keeps the port in the referer origin', function () {
    $_SERVER['HTTP_REFERER'] = 'http://example.com:8080/page';

    expect(security::origin_allowed())->toBeFalse();
});

it('leaves a request with neither header to the token', function () {
    // Server to server callers send no Origin and no Referer; refusing them outright would break
    // every non browser client, so the token alone decides
    expect(security::origin_allowed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Where the token is read from
|--------------------------------------------------------------------------
*/

it('reads the token from the request parameters', function () {
    req::$posts = ['csrf_token_name' => 'from-the-form'];

    expect(security::csrf_request_token())->toBe('from-the-form');
});

it('takes the token out of the parameters once it has read it', function () {
    req::$posts = req::$forms = ['csrf_token_name' => 'from-the-form', 'id' => 1];

    security::csrf_request_token();

    expect(req::$posts)->toBe(['id' => 1]);
    expect(req::$forms)->toBe(['id' => 1]);
});

it('reads the token from the header', function () {
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'from-the-header';

    expect(security::csrf_request_token())->toBe('from-the-header');
});

it('reads the token from the envelope', function () {
    // An encrypted request has no form fields until it is decoded, so the token travels inside
    // the envelope. An attacker who knows the public web key can still encrypt, but cannot read
    // the victim's cookie to know what to put here.
    envelope::configure([
        'clients'       => ['web' => $_ENV['CRYPT_KEY']],
        'replay_window' => 0,
    ]);

    $_SERVER['CONTENT_TYPE'] = crypt::MEDIA_TYPE;
    req::reset_input();
    req::set_raw(crypt::encode(json_encode([
        'ct'   => 'article',
        'ac'   => 'edit',
        'csrf' => 'from-the-envelope',
        'data' => ['id' => 10],
    ]), $_ENV['CRYPT_KEY'], 'plato-envelope:request:web'));
    $_SERVER['HTTP_X_CLIENT'] = 'web';

    envelope::resolve();

    expect(security::csrf_request_token())->toBe('from-the-envelope');
});

it('prefers the header over nothing at all', function () {
    req::$posts                   = [];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'from-the-header';

    expect(security::csrf_request_token())->toBe('from-the-header');
});

it('reports no token when the request carries none', function () {
    expect(security::csrf_request_token())->toBe('');
});

/*
|--------------------------------------------------------------------------
| Route based exclusion
|
| The old list matched a regex against the query string while the dispatcher
| routed on the merged request data, so a whitelisted query string could
| carry a different route in the body.
|--------------------------------------------------------------------------
*/

it('excludes by resolved route, not by query string', function () {
    route::resolve('/notify/callback?ct=admin&ac=del', 'POST');

    expect(route::matches(['notify:callback']))->toBeTrue();
    expect(route::matches(['admin:del']))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The ip whitelist
|
| csrf_white_ips skips the check outright, so every way of writing an entry
| that does not parse has to narrow the list rather than widen it.
|--------------------------------------------------------------------------
*/

it('matches an address inside a block and nothing outside it', function () {
    expect(security::match_cidr('10.1.2.3', '10.0.0.0/8'))->toBeTrue();
    expect(security::match_cidr('11.1.2.3', '10.0.0.0/8'))->toBeFalse();
    expect(security::match_cidr('255.255.255.255', '255.255.255.254/31'))->toBeTrue();
});

it('treats an entry with no mask as a single host', function () {
    expect(security::match_cidr('1.2.3.4', '1.2.3.4'))->toBeTrue();
    expect(security::match_cidr('1.2.3.5', '1.2.3.4'))->toBeFalse();
});

it('does not widen the whitelist for a malformed entry', function () {
    // A malformed mask must not be coerced to /0 and widen the allowlist.
    expect(security::match_cidr('1.2.3.4', '10.0.0.0/abc'))->toBeFalse();
    expect(security::match_cidr('1.2.3.4', '10.0.0.0/64'))->toBeFalse();
    expect(security::match_cidr('1.2.3.4', 'not-an-address/8'))->toBeFalse();
    expect(security::match_cidr('::1', '10.0.0.0/8'))->toBeFalse();
});

it('reads /0 as every address', function () {
    expect(security::match_cidr('1.2.3.4', '0.0.0.0/0'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The hash, and the request boundary
|
| capture() is per request, not per process: a resident worker calls it
| again for the next request, and two callers sharing one token means
| either of them can forge for the other.
|--------------------------------------------------------------------------
*/

it('issues a fresh hash for every request served by one process', function () {
    $cookies = $_COOKIE;
    $_COOKIE = [];

    req::configure(csrf_test_config());

    security::capture();
    $first = security::get_csrf_hash();

    security::capture();
    $second = security::get_csrf_hash();

    expect($first)->toMatch('/^v1\.[0-9a-f]{32}\.[0-9a-f]{64}$/');
    expect($second)->not->toBe($first);

    // Same caller coming back with its cookie still wins over a new hash, otherwise a page that
    // embeds sub requests would invalidate the form it just rendered
    $_COOKIE['csrf_cookie_name'] = $second;
    security::capture();

    expect(security::get_csrf_hash())->toBe($second);

    $_COOKIE = $cookies;
});

it('does not accept a signed token from another binding', function () {
    $cookies = $_COOKIE;
    $_COOKIE = [];

    req::configure(csrf_test_config('session-a'));
    security::capture();
    $first = security::get_csrf_hash();

    $_COOKIE['csrf_cookie_name'] = $first;
    req::configure(csrf_test_config('session-b'));
    security::capture();

    expect(security::get_csrf_hash())->not->toBe($first);

    $_COOKIE = $cookies;
});

it('rejects an unsigned token even when the cookie and form values match', function () {
    req::configure(csrf_test_config());
    security::capture();
    route::resolve('/article/edit', 'POST');

    $forged = 'v1.' . str_repeat('a', 32) . '.' . str_repeat('b', 64);

    req::$cookies = ['csrf_cookie_name' => $forged];
    req::$posts   = ['csrf_token_name' => $forged];
    req::$forms   = req::$posts;
    $_SERVER['HTTP_ORIGIN'] = 'http://example.com';

    expect(security::csrf_verify()->status())->toBe(403);
});

it('accepts a correctly signed token for the current binding', function () {
    req::configure(csrf_test_config());
    security::capture();
    route::resolve('/article/edit', 'POST');

    $token = security::get_csrf_hash();

    req::$cookies = ['csrf_cookie_name' => $token];
    req::$posts   = ['csrf_token_name' => $token];
    req::$forms   = req::$posts;
    $_SERVER['HTTP_ORIGIN'] = 'http://example.com';

    expect(security::csrf_verify())->toBeTrue();
});

it('fails closed when an enabled policy has no signing secret', function () {
    req::configure(array_replace(csrf_test_config(), ['csrf_secret' => '']));

    security::capture();
})->throws(RuntimeException::class, 'csrf: request.csrf_secret must contain at least 32 bytes');

it('fails closed when an enabled policy has no request binding', function () {
    req::configure([
        'csrf_token_on' => true,
        'csrf_secret'   => str_repeat('s', 32),
        'csrf_binding'  => static fn(): string => '',
    ]);

    security::capture();
})->throws(RuntimeException::class, 'csrf: request.csrf_binding must return a non-empty string');

it('issues no hash while csrf protection is off', function () {
    req::configure(['csrf_token_on' => false]);
    security::capture();

    expect(security::get_csrf_hash())->toBeNull();

    req::reset_config();
});
