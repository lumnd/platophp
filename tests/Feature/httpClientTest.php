<?php

/**
 * plato\http\client against a real server.
 *
 * The server is the PHP built-in one over a tiny router of this file's own, not the framework
 * fixture: what is being tested is the client, so the far end has to be able to answer a 500, a
 * 429 with a Retry-After, a redirect and a slow request on demand -- none of which a controller
 * should be made to do.
 */

use plato\http\client;
use plato\http\client_response;

const HTTP_CLIENT_HOST = 'localhost:8010';
const HTTP_CLIENT_BASE = 'http://' . HTTP_CLIENT_HOST;

/**
 * Absolute url of a path on the test server.
 */
function http_test_url(string $path = '/'): string
{
    return HTTP_CLIENT_BASE . $path;
}

/**
 * Where the router script and its counter file live.
 */
function http_test_dir(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'platophp-test-httpclient';
}

beforeAll(function () {
    $dir = http_test_dir();

    // Start from an empty directory rather than trusting the last run to have cleaned up. The
    // counter files carry how many times each ?key= has been asked, in a path that does not vary
    // per process, and afterAll only removes them when the suite finished normally. A run that was
    // killed left every retry case looking at a far end that had already used up its failures: the
    // request succeeded first time, so nothing retried, and the Retry-After case measured no wait
    // at all. That is the whole of what made these four cases flaky.
    foreach ( (array) glob($dir . '/*') as $stale )
    {
        @unlink((string) $stale);
    }

    is_dir($dir) || mkdir($dir, 0777, true);

    // A router that answers whatever the path asks it to. The retry cases need a far end that
    // fails a fixed number of times and then succeeds, which is what the counter file is for.
    file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$dir  = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('X-Method: ' . $_SERVER['REQUEST_METHOD']);

if ($path === '/echo')
{
    header('Content-Type: application/json');
    echo json_encode([
        'method'  => $_SERVER['REQUEST_METHOD'],
        'query'   => $_GET,
        'body'    => file_get_contents('php://input'),
        'type'    => $_SERVER['CONTENT_TYPE'] ?? '',
        'marker'  => $_SERVER['HTTP_X_MARKER'] ?? '',
        'agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);
    return true;
}

if ($path === '/status')
{
    http_response_code((int) ($_GET['code'] ?? 200));
    echo 'status';
    return true;
}

// Fails ($_GET['times']) times with 503, then answers 200. The count is per ?key=
if ($path === '/flaky')
{
    $file  = $dir . '/flaky_' . preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['key'] ?? 'k'));
    $seen  = (int) @file_get_contents($file);
    $times = (int) ($_GET['times'] ?? 1);

    file_put_contents($file, (string) ($seen + 1));

    if ($seen < $times)
    {
        if (!empty($_GET['retry_after']))
        {
            header('Retry-After: ' . (int) $_GET['retry_after']);
        }
        http_response_code(503);
        echo 'later';
        return true;
    }

    echo 'ok after ' . $seen;
    return true;
}

if ($path === '/slow')
{
    sleep((int) ($_GET['seconds'] ?? 2));
    echo 'awake';
    return true;
}

if ($path === '/redirect')
{
    header('Location: /echo', true, 302);
    return true;
}

http_response_code(404);
echo 'no';
return true;
PHP);

    $pid_file = $dir . '/server.pid';

    shell_exec(sprintf(
        'php -S %s %s > /dev/null 2>&1 & echo $! > %s',
        HTTP_CLIENT_HOST,
        escapeshellarg($dir . '/router.php'),
        escapeshellarg($pid_file)
    ));

    $deadline = microtime(true) + 10;
    while ( microtime(true) < $deadline )
    {
        $conn = @fsockopen('localhost', 8010, $errno, $errstr, 0.2);
        if ( $conn )
        {
            fclose($conn);
            return;
        }
        usleep(100000);
    }

    throw new RuntimeException('the test server did not come up on ' . HTTP_CLIENT_HOST);
});

afterAll(function () {
    $dir      = http_test_dir();
    $pid_file = $dir . '/server.pid';

    $pid = is_file($pid_file) ? (int) file_get_contents($pid_file) : 0;

    // Only kill it if it is still the server this run started. beforeAll empties the directory, so
    // the pid file cannot be an older run's -- but `php -S` also fails to bind when something else
    // already holds the port, and the pid recorded for that short-lived process may have been
    // handed to somebody else's by now.
    if ( $pid > 0 && strpos((string) shell_exec('ps -p ' . $pid . ' -o args= 2>/dev/null'), 'router.php') !== false )
    {
        shell_exec('kill ' . $pid);
    }

    foreach ( (array) glob($dir . '/*') as $file )
    {
        @unlink((string) $file);
    }

    @rmdir($dir);
});

beforeEach(function () {
    $this->client = new client();
});

/*
|--------------------------------------------------------------------------
| Requests
|--------------------------------------------------------------------------
*/

it('sends a GET and answers a client response object', function () {
    $answer = $this->client->get(http_test_url('/echo'));

    expect($answer)->toBeInstanceOf(client_response::class)
        ->and($answer->ok())->toBeTrue()
        ->and($answer->status())->toBe(200)
        ->and($answer->json()['method'])->toBe('GET')
        ->and($answer->attempts())->toBe(1);
});

it('reads response headers case insensitively', function () {
    $answer = $this->client->get(http_test_url('/echo'));

    expect($answer->header('X-Method'))->toBe('GET')
        ->and($answer->header('x-method'))->toBe('GET')
        ->and($answer->header('nothing', 'fallback'))->toBe('fallback');
});

it('appends the query array to the url', function () {
    $answer = $this->client->get(http_test_url('/echo'), ['query' => ['a' => 1, 'b' => 'two']]);

    expect($answer->json()['query'])->toBe(['a' => '1', 'b' => 'two']);
});

it('keeps a query string already on the url', function () {
    $answer = $this->client->get(http_test_url('/echo?a=1'), ['query' => ['b' => 2]]);

    expect($answer->json()['query'])->toBe(['a' => '1', 'b' => '2']);
});

it('sends an array body as a form', function () {
    $answer = $this->client->post(http_test_url('/echo'), ['name' => 'plato']);
    $row    = $answer->json();

    expect($row['method'])->toBe('POST')
        ->and($row['body'])->toBe('name=plato')
        ->and($row['type'])->toContain('application/x-www-form-urlencoded');
});

it('sends a json body with the content type that goes with it', function () {
    $answer = $this->client->post(http_test_url('/echo'), null, ['json' => ['name' => 'plato']]);
    $row    = $answer->json();

    expect($row['body'])->toBe('{"name":"plato"}')
        ->and($row['type'])->toContain('application/json');
});

it('takes headers as a map or as a list of lines', function () {
    $as_map  = $this->client->get(http_test_url('/echo'), ['headers' => ['X-Marker' => 'map']]);
    $as_list = $this->client->get(http_test_url('/echo'), ['headers' => ['X-Marker: list']]);

    expect($as_map->json()['marker'])->toBe('map')
        ->and($as_list->json()['marker'])->toBe('list');
});

it('sends any method it is given', function () {
    $answer = $this->client->request('PATCH', http_test_url('/echo'), ['json' => ['a' => 1]]);

    expect($answer->json()['method'])->toBe('PATCH');
});

it('resolves a relative url against base_uri', function () {
    $this->client->configure(['base_uri' => HTTP_CLIENT_BASE]);

    expect($this->client->get('/echo')->ok())->toBeTrue();
});

it('follows a redirect by default and can be told not to', function () {
    expect($this->client->get(http_test_url('/redirect'))->status())->toBe(200);
    expect($this->client->get(http_test_url('/redirect'), ['follow' => false])->status())->toBe(302);
});

/*
|--------------------------------------------------------------------------
| Failure
|--------------------------------------------------------------------------
*/

it('reports a status outside 2xx without throwing', function () {
    $answer = $this->client->get(http_test_url('/status?code=500'));

    expect($answer->status())->toBe(500)
        ->and($answer->ok())->toBeFalse()
        ->and($answer->server_error())->toBeTrue()
        ->and($answer->error())->toBeNull();
});

it('reports a transport failure as status 0 with an error', function () {
    // Nothing is listening on this port
    $answer = $this->client->get('http://localhost:8011/', ['connect_timeout' => 1, 'timeout' => 2]);

    expect($answer->status())->toBe(0)
        ->and($answer->error())->not->toBeNull()
        ->and($answer->failed())->toBeTrue();
});

it('honours the request timeout', function () {
    $started = hrtime(true);

    $answer = $this->client->get(http_test_url('/slow?seconds=5'), ['timeout' => 1]);

    expect($answer->status())->toBe(0)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(3.0);
});

it('throws only when it was asked to', function () {
    $this->client->get(http_test_url('/status?code=500'), ['throw_on_error' => true]);
})->throws(RuntimeException::class);

/*
|--------------------------------------------------------------------------
| Retries
|--------------------------------------------------------------------------
*/

it('retries a retryable status and reports how many attempts it took', function () {
    $answer = $this->client->get(http_test_url('/flaky?key=a&times=2'), [
        'retries'    => 3,
        'backoff_ms' => [1],
    ]);

    expect($answer->ok())->toBeTrue()
        ->and($answer->attempts())->toBe(3);
});

it('gives up once the retries are used and returns the last answer', function () {
    $answer = $this->client->get(http_test_url('/flaky?key=b&times=10'), [
        'retries'    => 1,
        'backoff_ms' => [1],
    ]);

    expect($answer->status())->toBe(503)
        ->and($answer->attempts())->toBe(2);
});

it('does not retry a status that is not in the list', function () {
    $answer = $this->client->get(http_test_url('/status?code=404'), [
        'retries'    => 3,
        'backoff_ms' => [1],
    ]);

    expect($answer->status())->toBe(404)
        ->and($answer->attempts())->toBe(1);
});

it('does not retry a POST unless it is told the method is safe to repeat', function () {
    // A timeout says nothing about whether the server processed the request
    $answer = $this->client->post(http_test_url('/flaky?key=c&times=5'), null, [
        'retries'    => 3,
        'backoff_ms' => [1],
    ]);

    expect($answer->attempts())->toBe(1);

    $allowed = $this->client->post(http_test_url('/flaky?key=d&times=1'), null, [
        'retries'       => 3,
        'backoff_ms'    => [1],
        'retry_methods' => ['POST'],
    ]);

    expect($allowed->attempts())->toBe(2);
});

it('waits as long as Retry-After says rather than as long as the backoff does', function () {
    // hrtime rather than microtime, because what is being asserted is a duration and the wall clock
    // is not a duration source: the clock the suite runs against is stepped by its time daemon, and
    // a correction of about 170ms landing inside the second the client sleeps made this case read
    // 0.83s for a sleep that really did last a second. A monotonic reading cannot be walked back.
    $started = hrtime(true);

    $answer = $this->client->get(http_test_url('/flaky?key=e&times=1&retry_after=1'), [
        'retries'    => 2,
        // Would have retried immediately; the header asks for a second
        'backoff_ms' => [1],
    ]);

    // attempts() carries the actual claim -- that the header was honoured over the backoff -- and
    // holds it whatever the clock does; the elapsed time only distinguishes the two wait lengths.
    expect($answer->ok())->toBeTrue()
        ->and($answer->attempts())->toBe(2)
        ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThan(0.9);
});

/*
|--------------------------------------------------------------------------
| Middleware and the pool
|--------------------------------------------------------------------------
*/

it('runs middleware around every request', function () {
    $this->client->middleware(function (array $request, callable $next) {
        $request['headers']['X-Marker'] = 'from middleware';

        return $next($request);
    });

    expect($this->client->get(http_test_url('/echo'))->json()['marker'])->toBe('from middleware');
});

it('lets a middleware answer without sending anything', function () {
    $this->client->middleware(function (array $request, callable $next) {
        return new client_response(299, 'from the middleware');
    });

    $answer = $this->client->get(http_test_url('/echo'));

    expect($answer->status())->toBe(299)
        ->and($answer->body())->toBe('from the middleware');
});

it('runs several requests at once and keeps the keys of the input', function () {
    $answers = $this->client->pool([
        'first'  => ['url' => http_test_url('/echo'), 'query' => ['n' => 1]],
        'second' => ['url' => http_test_url('/status?code=404')],
    ]);

    expect($answers)->toHaveKeys(['first', 'second'])
        ->and($answers['first']->json()['query'])->toBe(['n' => '1'])
        ->and($answers['second']->status())->toBe(404);
});

/*
|--------------------------------------------------------------------------
| Array compatibility API
|--------------------------------------------------------------------------
*/

it('keeps the shape http_request() has always returned', function () {
    $ret = $this->client->http_request(['url' => http_test_url('/echo'), 'post' => ['a' => 1]]);

    expect($ret)->toHaveKeys(['head', 'body', 'info'])
        ->and($ret['info']['status'])->toBe(200)
        ->and(json_decode($ret['body'], true)['body'])->toBe('a=1');
});

it('answers a null body and an errno on a transport failure, as it always did', function () {
    $ret = $this->client->http_request(['url' => 'http://localhost:8011/', 'timeout' => 1]);

    expect($ret['body'])->toBeNull()
        ->and($ret['info'])->toHaveKey('errno');
});

it('runs a list of array requests concurrently', function () {
    $ret = $this->client->http_request([
        ['url' => http_test_url('/echo')],
        ['url' => http_test_url('/status?code=404')],
    ], true);

    expect($ret[0]['info']['status'])->toBe(200)
        ->and($ret[1]['info']['status'])->toBe(404);
});
