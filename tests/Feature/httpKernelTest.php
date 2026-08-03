<?php

/**
 * End to end request handling, driven through the PHP built-in server.
 *
 * tests/Fixtures/server.php is the entry point; everything here goes over a real socket, so it
 * covers the parts a unit test cannot reach: header parsing, the response headers, the status
 * codes the dispatcher produces, and the encrypted reply.
 */

use plato\security\crypt;
use plato\http\client;

const HTTP_KERNEL_HOST = 'localhost:8000';
const HTTP_KERNEL_BASE = 'http://' . HTTP_KERNEL_HOST;

/**
 * Runtime directory of the server process. It cannot share the one the suite uses, the pid is a
 * different one; the name still matches the prefix plato_test_rmdir() will delete.
 *
 * @return string
 */
function http_kernel_data(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-server';
}

/**
 * Absolute url of a path on the test server.
 *
 * @param string $path
 * @return string
 */
function http_kernel_url(string $path = '/'): string
{
    return HTTP_KERNEL_BASE . $path;
}

/**
 * Send a request without following redirects, and return the raw response headers.
 *
 * @param string               $path
 * @param array<string, string> $headers
 * @param string                $method
 * @return string
 */
function http_kernel_headers(string $path, array $headers = [], string $method = 'GET'): string
{
    $lines = '';
    foreach ( $headers as $name => $value )
    {
        $lines .= $name . ': ' . $value . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'header'          => $lines,
            'method'          => $method,
            'follow_location' => 0,
            'ignore_errors'   => true,
        ],
    ]);

    file_get_contents(http_kernel_url($path), false, $context);

    return implode("\n", $http_response_header);
}

beforeAll(function () {
    $router   = dirname(__DIR__) . '/Fixtures/server.php';
    $pid_file = sys_get_temp_dir() . '/platophp-test-server.pid';

    // The server is a separate process, so it gets its own runtime directory; afterAll removes it
    shell_exec(sprintf(
        'PLATOPHP_TEST_DATA=%s php -S %s %s > /dev/null 2>&1 & echo $! > %s',
        escapeshellarg(http_kernel_data()),
        HTTP_KERNEL_HOST,
        escapeshellarg($router),
        escapeshellarg($pid_file)
    ));

    // Poll until the port actually accepts a connection. A fixed sleep is not enough on a busy
    // machine, and the failure shows up as a different test breaking on every run.
    $deadline = microtime(true) + 10;
    while ( microtime(true) < $deadline )
    {
        $conn = @fsockopen('localhost', 8000, $errno, $errstr, 0.2);
        if ( $conn )
        {
            fclose($conn);
            return;
        }
        usleep(100000);
    }

    throw new RuntimeException('PHP built-in server did not come up on ' . HTTP_KERNEL_HOST);
});

afterAll(function () {
    $pid_file = sys_get_temp_dir() . '/platophp-test-server.pid';
    if ( is_file($pid_file) )
    {
        shell_exec('kill ' . (int) file_get_contents($pid_file));
        unlink($pid_file);
    }

    plato_test_rmdir(http_kernel_data());
});

/*
|--------------------------------------------------------------------------
| Request parsing
|--------------------------------------------------------------------------
*/

it('answers the document root with the default route', function () {
    expect(file_get_contents(http_kernel_url()))->toBeJson();
});

it('parses a json body without changing its values', function () {
    $data = [
        'a' => "sdjflkjalfdjlfjsladjflkdsjk'sj<b>" . PHP_EOL . 'sdjfasjflksdjlkj<>?',
    ];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => json_encode($data),
        'header' => ['Content-Type:application/json'],
    ]);
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    expect($body['data']['item']['a'])->toBe($data['a']);
    expect($body['data']['headers']['Content-Type'])->toBe('application/json');
});

it('rejects malformed json instead of treating it as a form', function () {
    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => '{not-json',
        'header' => ['Content-Type:application/json'],
    ]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(400)
        ->and($body)->toBeArray()
        ->and($body['code'])->toBe(-400);
});

it('parses a form encoded body', function () {
    $data = ['a' => 'sdjflkjalfdjlfjs'];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => $data,
        'header' => ['Content-Type:application/x-www-form-urlencoded'],
    ]);
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    expect($body['data']['item']['a'])->toBe($data['a']);
    expect($body['data']['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded');
});

/*
|--------------------------------------------------------------------------
| Routing
|--------------------------------------------------------------------------
*/

it('routes from the path', function () {
    $ret = (new client())->http_request([
        'url'  => http_kernel_url('/unit_test/test'),
        'post' => ['a' => 'from_path'],
    ]);
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    expect($body['data']['item']['a'])->toBe('from_path');
});

it('no longer routes from the query string', function () {
    // ?ct=&ac= is gone: this request has to land on the default route, not on unit_test:test
    $ret = (new client())->http_request([
        'url'  => http_kernel_url('/?ct=unit_test&ac=test'),
        'post' => ['a' => 'from_query'],
    ]);
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    // ct/ac are ordinary query parameters now, so they show up in the parameter bag
    expect($body['data']['item']['ct'])->toBe('unit_test');
});

it('refuses an upper case path', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/Unit_Test/Test')]);

    expect($ret['info']['status'])->toBe(404);
});

it('redirects a trailing slash to the canonical path', function () {
    $headers = http_kernel_headers('/unit_test/test/');

    expect($headers)->toContain('301');
    // The target is assembled from validated segments, it never echoes the raw input back
    expect($headers)->toContain('Location: /unit_test/test');
});

/*
|--------------------------------------------------------------------------
| Action whitelist
|--------------------------------------------------------------------------
*/

it('binds a declared action to its method', function () {
    $ok = (new client())->http_request([
        'url'  => http_kernel_url('/secure/ping'),
        'post' => ['a' => 'x'],
    ]);

    expect($ok['info']['status'])->toBe(200);

    // $actions declares ping as POST only
    $bad = (new client())->http_request(['url' => http_kernel_url('/secure/ping')]);

    expect($bad['info']['status'])->toBe(405);
});

it('adds the action methods to a 405 Allow header', function () {
    $headers = http_kernel_headers('/secure/ping');

    expect($headers)->toContain('405 Method Not Allowed')
        ->and($headers)->toContain('Allow: POST');
});

it('lets HEAD reach a GET action', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/secure/read')]);

    expect($ret['info']['status'])->toBe(200);
});

it('refuses a public method the declared list omits', function () {
    $ret = (new client())->http_request([
        'url'  => http_kernel_url('/secure/hidden'),
        'post' => ['a' => 'x'],
    ]);

    expect($ret['info']['status'])->toBe(404);
});

it('refuses an inherited public method', function () {
    // ctl_unit_test extends ctl_index, so index() is declared by the parent
    $ret = (new client())->http_request(['url' => http_kernel_url('/unit_test/index')]);

    expect($ret['info']['status'])->toBe(404);
});

it('skips authentication for an action that declares auth none', function () {
    // The identity header is there and is still ignored: the callback never runs
    $ret = (new client())->http_request([
        'url' => http_kernel_url('/auth/open'),
        'header' => ['X-Test-Identity:7'],
    ]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(200)
        ->and($body['auth'])->toBeNull();
});

it('runs an optional action without an identity', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/auth/greet')]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(200)
        ->and($body['uid'])->toBeNull();
});

it('hands an identity to an optional action when there is one', function () {
    $ret = (new client())->http_request([
        'url' => http_kernel_url('/auth/greet'),
        'header' => ['X-Test-Identity:7'],
    ]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(200)
        ->and($body['uid'])->toBe('7');
});

it('lets authentication refuse an optional action', function () {
    $ret = (new client())->http_request([
        'url' => http_kernel_url('/auth/greet'),
        'header' => ['X-Test-Refuse:1'],
    ]);

    expect($ret['info']['status'])->toBe(401);
});

it('answers 401 for a required action when authentication returns no identity', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/auth/me')]);

    // The visitor's state, not a programming error: 401 the way a failed csrf check answers 403
    expect($ret['info']['status'])->toBe(401)
        ->and($ret['body'])->not->toContain('uid');
});

it('returns an authentication reply without executing the action', function () {
    $ret = (new client())->http_request([
        'url' => http_kernel_url('/auth/me'),
        'header' => ['X-Test-Refuse:1'],
    ]);

    expect($ret['info']['status'])->toBe(401)
        ->and(json_decode($ret['body'], true))->toBe(['code' => 401, 'msg' => 'Unauthorized']);
});

it('hands the authenticated identity to an action that requires it', function () {
    $ret = (new client())->http_request([
        'url' => http_kernel_url('/auth/me'),
        'header' => ['X-Test-Identity:7'],
    ]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(200)
        ->and($body['uid'])->toBe('7');
});

it('answers a bound authenticated write preflight without running authentication or the action', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/auth/save'), [
        'headers' => [
            'Origin'                         => 'https://app.example.com',
            'Access-Control-Request-Method'  => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-Test-Identity',
        ],
    ]);

    expect($response->status())->toBe(204)
        ->and($response->body())->toBe('')
        ->and($response->header('Access-Control-Allow-Origin'))->toBe('*')
        ->and($response->header('Access-Control-Allow-Methods'))->toBe('POST')
        ->and($response->header('Access-Control-Allow-Headers'))->toBe('Content-Type, X-Test-Identity');
});

it('runs route middleware around the automatic preflight response without running the action', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/middleware/wrapped'), [
        'headers' => [
            'Origin'                        => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
        ],
    ]);

    expect($response->status())->toBe(204)
        ->and($response->body())->toBe('')
        ->and($response->header('X-Middleware-Before'))->toBe('1')
        ->and($response->header('X-Middleware-After'))->toBe('1');
});

it('keeps an ordinary options request on the action method binding path', function () {
    $headers = http_kernel_headers('/auth/save', ['Origin' => 'https://app.example.com'], 'OPTIONS');

    expect($headers)->toContain('405 Method Not Allowed')
        ->and($headers)->toContain('Allow: POST');
});

it('refuses a preflight whose requested method the action does not allow', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/auth/save'), [
        'headers' => [
            'Origin'                        => 'https://app.example.com',
            'Access-Control-Request-Method' => 'DELETE',
        ],
    ]);

    expect($response->status())->toBe(405)
        ->and($response->header('Allow'))->toBe('POST');
});

it('caches a preflight answer for the configured lifetime', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/auth/save'), [
        'headers' => [
            'Origin'                        => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
        ],
    ]);

    expect($response->status())->toBe(204)
        ->and($response->header('Access-Control-Max-Age'))->toBe('600');
});

it('approves no request headers at all when one of them is not configured', function () {
    // The fixture allows Content-Type and X-Test-Identity, and a partial approval would let the
    // browser send a request the server never agreed to
    $response = (new client())->request('OPTIONS', http_kernel_url('/auth/save'), [
        'headers' => [
            'Origin'                         => 'https://app.example.com',
            'Access-Control-Request-Method'  => 'POST',
            'Access-Control-Request-Headers' => 'content-type, x-forwarded-for',
        ],
    ]);

    expect($response->status())->toBe(204)
        ->and($response->header('Access-Control-Allow-Headers'))->toBeNull();
});

it('matches the configured request headers without regard to case', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/auth/save'), [
        'headers' => [
            'Origin'                         => 'https://app.example.com',
            'Access-Control-Request-Method'  => 'POST',
            'Access-Control-Request-Headers' => 'CONTENT-TYPE, x-test-identity',
        ],
    ]);

    expect($response->status())->toBe(204)
        ->and($response->header('Access-Control-Allow-Headers'))->toBe('CONTENT-TYPE, x-test-identity');
});

/*
|--------------------------------------------------------------------------
| The CLI marker is not an http method
|--------------------------------------------------------------------------
|
| check_action() stands method binding aside for it and csrf_verify() counts it among the safe
| methods, so a request that could name it would reach a bound, csrf protected write action with
| neither check applied.
|
| The request line itself is not tested here: the built-in server answers 501 to a method it does
| not know, so `REQUEST_METHOD: CLI` never reaches PHP under it. nginx and Apache do forward it,
| which is what routeTest covers against route::resolve().
*/

it('refuses a preflight for the CLI marker without executing the options action', function () {
    $response = (new client())->request('OPTIONS', http_kernel_url('/middleware/wrapped'), [
        'headers' => [
            'Origin'                        => 'https://app.example.com',
            'Access-Control-Request-Method' => 'CLI',
        ],
    ]);

    expect($response->status())->toBe(405)
        ->and($response->header('Allow'))->not->toContain('CLI')
        ->and($response->body())->not->toContain('wrapped');
});

it('reports a malformed action declaration as a server error, not as a missing action', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/malformed/legacy')]);

    expect($ret['info']['status'])->toBe(500)
        ->and($ret['body'])->not->toContain('reached');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('runs the middleware configured for a route around the action', function () {
    // app/config/config.php puts middleware\marker on 'middleware:*'
    $headers = http_kernel_headers('/middleware/wrapped');

    expect($headers)->toContain('X-Middleware-Before: 1')
        ->and($headers)->toContain('X-Middleware-After: 1');
});

it('lets a middleware answer without reaching the action', function () {
    $ret = (new client())->http_request(['url' => http_kernel_url('/middleware/refused')]);

    expect($ret['info']['status'])->toBe(429)
        ->and($ret['body'])->toContain('refused by middleware')
        ->and($ret['body'])->not->toContain('the action ran after all');
});

it('turns an action exception into one safe json response', function () {
    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/failure/boom'),
        'post'   => '{}',
        'header' => ['Content-Type:application/json'],
    ]);
    $body = json_decode($ret['body'], true);

    expect($ret['info']['status'])->toBe(500)
        ->and($body)->toBeArray()
        ->and($body['code'])->toBe(-500)
        ->and($body['msg'])->toBe('Internal Server Error')
        ->and($ret['body'])->not->toContain('private failure detail');
});

it('leaves a route no pattern matches alone', function () {
    $headers = http_kernel_headers('/index/index');

    expect($headers)->not->toContain('X-Middleware-Before');
});

/*
|--------------------------------------------------------------------------
| Encrypted envelope
|--------------------------------------------------------------------------
*/

it('takes the route and the payload out of the envelope', function () {
    // ct/ac/method are envelope metadata, the parameters travel in data. Only an empty path
    // ('/') reaches the crypto source, and X-Client names the key to decrypt with.
    $envelope = [
        'ct'     => 'unit_test',
        'ac'     => 'test',
        'method' => 'POST',
        'data'   => [
            'a' => 'sdjflkjalfdjlfjs',
            'b' => 'sdjflkjalfdjlfjs>sjdfj>N',
        ],
    ];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => crypt::encode(
            json_encode($envelope),
            $_ENV['CRYPT_KEY'],
            'plato-envelope:request:web'
        ),
        'header' => ['X-Client:web', 'Content-Type:' . crypt::MEDIA_TYPE],
    ]);

    // This controller writes its JSON by hand rather than through resp::response(), so the reply
    // is plaintext; response encryption has its own test below
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    // The parameters are replaced by the envelope payload, the metadata stays out of them
    expect($body['data']['item'])->toBe($envelope['data']);
});

it('encrypts the reply of an envelope request', function () {
    $envelope = [
        'ct'     => 'secure',
        'ac'     => 'ping',
        'method' => 'POST',
        'data'   => ['a' => 'encrypted'],
    ];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => crypt::encode(
            json_encode($envelope),
            $_ENV['CRYPT_KEY'],
            'plato-envelope:request:web'
        ),
        'header' => ['X-Client:web', 'Content-Type:' . crypt::MEDIA_TYPE],
    ]);

    // Nothing readable in the clear
    expect(@json_decode($ret['body'], true))->toBeNull();

    $body = @json_decode((string) crypt::decode(
        $ret['body'],
        $_ENV['CRYPT_KEY'],
        'plato-envelope:response:web'
    ), true);

    expect($body)->toBeArray();
    expect($body['msg'])->toBe('pong');
    expect($body['data']['item'])->toBe($envelope['data']);
});

it('refuses an encrypted request that names no client', function () {
    // No X-Client means no valid envelope, so the request falls back to the default route
    // index/index instead of being taken as unit_test:test
    $envelope = ['ct' => 'unit_test', 'ac' => 'test', 'data' => ['a' => 'x']];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => crypt::encode(
            json_encode($envelope),
            $_ENV['CRYPT_KEY'],
            'plato-envelope:request:web'
        ),
        'header' => ['Content-Type:' . crypt::MEDIA_TYPE],
    ]);

    // A plaintext reply proves the crypto branch was not taken
    $body = @json_decode($ret['body'], true);

    expect($body)->toBeArray();
    expect($body['data']['item'])->not->toHaveKey('a');
});

it('refuses a plaintext request to a route that requires an envelope', function () {
    // Fixtures/app/config/config.php sets route.crypto_required = ['secure:any']. The check runs
    // against the resolved route, independently of request parameters.
    $ret = (new client())->http_request([
        'url'  => http_kernel_url('/secure/any'),
        'post' => ['a' => 'plaintext'],
    ]);

    expect($ret['info']['status'])->toBe(404);
});

it('accepts an envelope request to a route that requires one', function () {
    $envelope = [
        'ct'     => 'secure',
        'ac'     => 'any',
        'method' => 'POST',
        'data'   => ['a' => 'encrypted'],
    ];

    $ret = (new client())->http_request([
        'url'    => http_kernel_url('/'),
        'post'   => crypt::encode(
            json_encode($envelope),
            $_ENV['CRYPT_KEY'],
            'plato-envelope:request:web'
        ),
        'header' => ['X-Client:web', 'Content-Type:' . crypt::MEDIA_TYPE],
    ]);

    $body = @json_decode((string) crypt::decode(
        $ret['body'],
        $_ENV['CRYPT_KEY'],
        'plato-envelope:response:web'
    ), true);

    expect($body)->toBeArray();
    expect($body['msg'])->toBe('any');
});

/*
|--------------------------------------------------------------------------
| Response headers
|--------------------------------------------------------------------------
*/

it('answers a cross origin request with the wildcard and no credentials', function () {
    // The framework defaults to security.allow_origin = ['*']; with a wildcard it must not echo
    // the origin back, and must not send Allow-Credentials
    $headers = http_kernel_headers('/', ['Origin' => 'https://evil.example']);

    expect($headers)->toContain('Access-Control-Allow-Origin: *');
    expect($headers)->not->toContain('https://evil.example');
    expect(strtolower($headers))->not->toContain('access-control-allow-credentials');
});
