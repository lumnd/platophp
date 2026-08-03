<?php
/**
 * Entry point of the test application.
 *
 * tests/Feature/httpKernelTest.php serves this file with the PHP built-in server and drives a
 * real request through the whole path: routing, the encrypted envelope, the declared action
 * lists and the response headers. It plays the part public/index.php plays in a real
 * application, which is why it is the one place allowed to touch php.ini settings -- the
 * framework itself must never do that to its host.
 */

namespace control;

use plato\http\req;
use plato\http\resp;
use plato\plato;

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if ( !is_file($autoload) )
{
    // Installed under vendor/ of a host project
    $autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
}
require_once $autoload;

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

plato::registry([
    'app_path' => __DIR__ . '/app',
    // Given by httpKernelTest so it can clean up after the server; the fallback only matters if
    // this file is served by hand
    'data_path' => getenv('PLATOPHP_TEST_DATA') ?: sys_get_temp_dir() . '/platophp-test-server',
    'env_path'  => __DIR__ . '/app/.env.testing',
    'debug'     => false,
    'env'       => 'dev',
    'check_purview_handle' => static function (string $ct, string $ac): ?object {
        if ( req::headers('x-test-refuse', '') !== '' )
        {
            return resp::json(['code' => 401, 'msg' => 'Unauthorized'], 401);
        }

        $uid = (string) req::headers('x-test-identity', '');

        return $uid === '' ? null : (object) ['uid' => $uid, 'route' => $ct . ':' . $ac];
    },
]);

/**
 * Plain controller, echoes back what the framework parsed out of the request.
 *
 * Uses raw JSON rather than resp::response(), so the reply stays readable
 * even for an envelope request; ctl_secure is the one that exercises response encryption.
 */
class ctl_index
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'index' => ['methods' => ['GET', 'POST'], 'auth' => 'none'],
    ];

    public function index()
    {
        $data['item']    = req::item();
        $data['headers'] = req::headers();

        return resp::raw(json_encode(
            ['code' => 0, 'msg' => 'success', 'data' => $data, 'timestamp' => plato::timestamp()],
            JSON_UNESCAPED_UNICODE
        ), 'application/json');
    }
}

/**
 * Middleware target: the pipeline configured for these routes is in app/config/config.php.
 */
class ctl_middleware
{
    /** @var array<string, array{methods: list<string>|string, auth: string}> */
    public static $actions = [
        'wrapped' => ['methods' => '*', 'auth' => 'none'],
        'refused' => ['methods' => '*', 'auth' => 'none'],
    ];

    public function wrapped()
    {
        return resp::json(['code' => 0, 'msg' => 'wrapped']);
    }

    /** Never reached: middleware\refuse answers instead of calling $next */
    public function refused()
    {
        return resp::json(['code' => 0, 'msg' => 'the action ran after all']);
    }
}

/**
 * Inherits index() without declaring it, so the suite can prove inherited public methods are
 * not routable.
 */
class ctl_unit_test extends ctl_index
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'test' => ['methods' => ['GET', 'POST'], 'auth' => 'none'],
    ];

    public function test()
    {
        return $this->index();
    }
}

/**
 * Declared action list plus resp::response(), which encrypts the reply of an envelope request.
 */
class ctl_secure
{
    /** @var array<string, array{methods: list<string>|string, auth: string}> */
    public static $actions = [
        'ping' => ['methods' => ['POST'], 'auth' => 'none'],
        'read' => ['methods' => ['GET'], 'auth' => 'none'],
        'any'  => ['methods' => '*', 'auth' => 'none'],
    ];

    public function ping()
    {
        return resp::response(0, ['item' => req::item()], 'pong');
    }

    public function read()
    {
        return resp::response(0, ['item' => req::item()], 'read');
    }

    public function any()
    {
        return resp::response(0, ['item' => req::item()], 'any');
    }

    /** Not declared in $actions, so it must not be routable */
    public function hidden()
    {
        return resp::response(0, [], 'hidden');
    }
}

/**
 * Throws inside an action so the HTTP boundary can prove it emits one safe error response.
 */
class ctl_failure
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'boom' => ['methods' => ['POST'], 'auth' => 'none'],
    ];

    public function boom()
    {
        throw new \RuntimeException('private failure detail');
    }
}

/**
 * Route-authentication fixture: the action declaration, not a separate route list, decides whether
 * check_purview_handle runs.
 */
class ctl_auth
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'open'  => ['methods' => ['GET'], 'auth' => 'none'],
        'greet' => ['methods' => ['GET'], 'auth' => 'optional'],
        'me'    => ['methods' => ['GET'], 'auth' => 'required'],
        'save'  => ['methods' => ['POST'], 'auth' => 'required'],
    ];

    public function open()
    {
        return resp::json(['auth' => plato::$auth]);
    }

    /** Public, but knows who you are when you say so: what auth = optional is for */
    public function greet()
    {
        return resp::json(['uid' => plato::$auth->uid ?? null]);
    }

    public function me()
    {
        return resp::json(['uid' => plato::$auth->uid]);
    }

    public function save()
    {
        return resp::json(['executed' => true, 'uid' => plato::$auth->uid]);
    }
}

/**
 * Declaration the framework cannot read: structured metadata missing its auth policy. It reports
 * as a server error rather than as a 404, so that it does not look like a routing mistake.
 */
class ctl_malformed
{
    /** @var array<string, mixed> */
    public static $actions = [
        'legacy' => ['methods' => ['GET']],
    ];

    public function legacy()
    {
        return resp::json(['reached' => true]);
    }
}

plato::run();
