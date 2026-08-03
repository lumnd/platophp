<?php

/**
 * plato: bootstrap and dispatch.
 */

namespace control;

use Exception;
use plato\cache\cache;
use plato\cache\repository;
use plato\database\db;
use plato\exception\auth_exception;
use plato\http\reply;
use plato\http\resp;
use plato\plato;

/**
 * Controller the CLI dispatch test lands on. run() resolves ct/ac to index/index by default,
 * and throwing from index() is how the test observes that the call happened.
 */
class ctl_index
{
    public function index()
    {
        throw new Exception('cli run index/index', 1);
    }
}

it('dispatches the default route under cli', function () {
    plato::run();
})->throws(Exception::class, 'cli run index/index');

it('builds the controller class in the configured namespace', function () {
    $previous = plato::$config['controller_namespace'] ?? null;

    try
    {
        expect(plato::controller_class('index'))->toBe('control\ctl_index');

        // What lets two applications in one repository each hold a ctl_index of their own
        plato::$config['controller_namespace'] = 'admin\control';
        expect(plato::controller_class('index'))->toBe('admin\control\ctl_index');

        // Whether the configured value carries backslashes at either end is not the caller's
        // problem, both spellings name the same namespace
        plato::$config['controller_namespace'] = '\api\control\\';
        expect(plato::controller_class('index'))->toBe('api\control\ctl_index');

        // The global namespace, for an application that does not namespace its controllers
        plato::$config['controller_namespace'] = '';
        expect(plato::controller_class('index'))->toBe('ctl_index');
    }
    finally
    {
        plato::$config['controller_namespace'] = $previous;
    }
});

it('dispatches into the configured controller namespace', function () {
    $previous = plato::$config['controller_namespace'] ?? null;

    plato::$config['controller_namespace'] = 'admin\control';

    try
    {
        plato::run();
    }
    finally
    {
        plato::$config['controller_namespace'] = $previous;
    }
})->throws(Exception::class, 'cli run admin index/index');

it('formats a framework error code into a message', function () {
    expect(plato::fmt_code(1001, [plato::app_path()]))->toBeString();
});

it('reports the request totals', function () {
    expect(plato::app_total())->toBeArray();
});

it('floors the memory total at zero when the peak is below the request baseline', function () {
    $start_mem = new \ReflectionProperty(plato::class, '_start_mem');
    $previous  = $start_mem->getValue();
    $start_mem->setValue(null, memory_get_peak_usage() + 1024 * 1024);

    try
    {
        expect(plato::app_total()[1])->toBe(0);
    }
    finally
    {
        $start_mem->setValue(null, $previous);
    }
});

it('scopes the memory total to the current resident request', function () {
    $start_time = new \ReflectionProperty(plato::class, '_start_time');
    $start_mem  = new \ReflectionProperty(plato::class, '_start_mem');
    $timestamp  = new \ReflectionProperty(plato::class, '_timestamp');
    $previous   = [$start_time->getValue(), $start_mem->getValue(), $timestamp->getValue()];

    try
    {
        $heavy = str_repeat('x', 4 * 1024 * 1024);
        expect(strlen($heavy))->toBe(4 * 1024 * 1024);
        unset($heavy);

        plato::restamp();

        expect(plato::app_total()[1])->toBeLessThan(1024 * 1024);
    }
    finally
    {
        $start_time->setValue(null, $previous[0]);
        $start_mem->setValue(null, $previous[1]);
        $timestamp->setValue(null, $previous[2]);
    }
})->skip(
    !function_exists('memory_reset_peak_usage'),
    'memory_reset_peak_usage() requires PHP 8.2 or later'
);

it('clears request-scoped cache memoization and query logs', function () {
    $cache = cache::repository();
    $memo  = new \ReflectionProperty(repository::class, '_memo');
    $memo->setValue($cache, ['memoized-key' => 'old request']);
    $logging = db::logging_override();
    db::set_logging(true);
    db::record('test', 'select from an old request', [], 0.001, false);

    plato::reset_request();

    expect($memo->getValue($cache))->toBe([])
        ->and(db::queries())->toBe([]);

    db::set_logging($logging);
});

it('resets application request state after framework state', function () {
    $previous = plato::$config['reset_handle'] ?? null;
    $seen     = null;

    plato::$auth = (object) ['uid' => 7];
    plato::$ct   = 'user';
    plato::$ac   = 'show';
    plato::$config['reset_handle'] = function () use (&$seen): void {
        $seen = [plato::$auth, plato::$ct, plato::$ac];
    };

    try
    {
        plato::reset_request();

        expect($seen)->toBe([null, '', '']);
    }
    finally
    {
        if ( $previous === null )
        {
            unset(plato::$config['reset_handle']);
        }
        else
        {
            plato::$config['reset_handle'] = $previous;
        }
    }
});

it('falls back from the registry environment to APP_ENV and then to pub', function () {
    $registry_env = plato::$config['env'] ?? '';
    $app_env      = $_ENV['APP_ENV'] ?? null;

    try
    {
        // What registry() was given wins
        expect(plato::env())->toBe('dev');
        expect(plato::is_env('dev'))->toBeTrue();

        // Without it, APP_ENV from .env
        plato::$config['env'] = '';
        $_ENV['APP_ENV']      = 'pre';
        expect(plato::env())->toBe('pre');

        // With neither, pub
        unset($_ENV['APP_ENV']);
        expect(plato::env())->toBe('pub');
    }
    finally
    {
        plato::$config['env'] = $registry_env;
        if ( $app_env === null )
        {
            unset($_ENV['APP_ENV']);
        }
        else
        {
            $_ENV['APP_ENV'] = $app_env;
        }
    }
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| The action declaration decides whether check_purview_handle runs; these drive plato::handle()
| in process, as a CLI request that opted into authentication with cli_auth.
*/

/** auth = required: an identity is mandatory */
class ctl_auth_required
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'me' => ['methods' => ['GET'], 'auth' => 'required'],
    ];

    public function me()
    {
        return 'reached';
    }
}

/** auth = optional: public, but knows the visitor when there is one */
class ctl_auth_optional
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'greet' => ['methods' => ['GET'], 'auth' => 'optional'],
    ];

    public function greet()
    {
        return plato::$auth->uid ?? 'anonymous';
    }
}

/** auth = none: the callback is not called at all */
class ctl_auth_open
{
    /** @var array<string, array{methods: list<string>, auth: string}> */
    public static $actions = [
        'open' => ['methods' => ['GET'], 'auth' => 'none'],
    ];

    public function open()
    {
        return 'open';
    }
}

/**
 * Dispatch ct:ac in process with the given authentication callback, as a CLI request that opted
 * into authentication. Puts the configuration back whatever the dispatch did with it.
 *
 * @param string        $ct
 * @param string        $ac
 * @param callable|null $purview
 * @return mixed
 */
function dispatch_with_auth($ct, $ac, $purview)
{
    $config = plato::$config;

    plato::$config['cli_auth']             = true;
    plato::$config['check_purview_handle'] = $purview;
    plato::$auth                           = null;

    // handle() answers with resp::prepared() when the dispatch left one there, so a case that
    // ends in a framework rendered reply -- the 401 -- would otherwise answer for the next one
    resp::reset();

    try
    {
        return plato::handle(['ct' => $ct, 'ac' => $ac]);
    }
    finally
    {
        plato::$config = $config;
    }
}

/**
 * The error code an auth_exception came out with, 0 when the dispatch went through.
 *
 * @param string        $ct
 * @param string        $ac
 * @param callable|null $purview
 * @return int
 */
function auth_failure_code($ct, $ac, $purview)
{
    try
    {
        dispatch_with_auth($ct, $ac, $purview);

        return 0;
    }
    catch (auth_exception $e)
    {
        return $e->getCode();
    }
}

it('refuses to dispatch a required action with no authentication callback configured', function () {
    // A configuration mistake, not a signed out visitor: nothing was asked, so nothing can be
    // concluded about who is here
    expect(auth_failure_code('auth_required', 'me', null))->toBe(2013);
});

it('answers 401 for a required action when authentication finds nobody', function () {
    $reply = dispatch_with_auth('auth_required', 'me', fn () => null);

    expect($reply)->toBeInstanceOf(reply::class)
        ->and($reply->status())->toBe(401)
        ->and(plato::$auth)->toBeNull();
});

it('refuses a value that is neither an identity nor a reply', function () {
    expect(auth_failure_code('auth_required', 'me', fn () => true))->toBe(2014)
        ->and(auth_failure_code('auth_optional', 'greet', fn () => ['uid' => 7]))->toBe(2014);
});

it('lets the callback answer a required action its own way instead of the 401', function () {
    // Built by hand rather than through resp::json(), which would leave a prepared response in
    // resp's static state for every dispatch after this one
    $own = new reply(401, [], '{"code":401,"msg":"sign in"}');

    expect(dispatch_with_auth('auth_required', 'me', fn () => $own))->toBe($own);
});

it('leaves no identity behind when the callback broke its contract', function () {
    auth_failure_code('auth_required', 'me', fn () => 'not-an-object');

    expect(plato::$auth)->toBeNull();
});

it('dispatches a required action with the identity the callback returned', function () {
    $identity = (object) ['uid' => 7];

    expect(dispatch_with_auth('auth_required', 'me', fn () => $identity))->toBe('reached')
        ->and(plato::$auth)->toBe($identity);
});

it('dispatches an optional action when authentication finds nobody', function () {
    expect(dispatch_with_auth('auth_optional', 'greet', fn () => null))->toBe('anonymous')
        ->and(plato::$auth)->toBeNull();
});

it('dispatches an optional action with no authentication callback configured', function () {
    // Unlike a required action, this is a configuration the framework has nothing to say about
    expect(dispatch_with_auth('auth_optional', 'greet', null))->toBe('anonymous');
});

it('dispatches an optional action with the identity the callback returned', function () {
    expect(dispatch_with_auth('auth_optional', 'greet', fn () => (object) ['uid' => 7]))->toBe(7);
});

it('does not call the authentication callback for an action that declares auth none', function () {
    $called = false;

    $result = dispatch_with_auth('auth_open', 'open', function () use (&$called) {
        $called = true;

        return (object) ['uid' => 7];
    });

    expect($result)->toBe('open')
        ->and($called)->toBeFalse()
        ->and(plato::$auth)->toBeNull();
});

it('skips authentication under cli unless cli_auth is set', function () {
    $config = plato::$config;
    $called = false;

    plato::$config['cli_auth']             = false;
    plato::$config['check_purview_handle'] = function () use (&$called) {
        $called = true;

        return null;
    };

    resp::reset();

    try
    {
        // Required, no identity: it would be an error if authentication had run at all
        expect(plato::handle(['ct' => 'auth_required', 'ac' => 'me']))->toBe('reached')
            ->and($called)->toBeFalse();
    }
    finally
    {
        plato::$config = $config;
    }
});

namespace admin\control;

use Exception;

/**
 * The same ct=index the controller above answers, in a second namespace. Two of these coexisting
 * is the whole point of controller_namespace: one repository, one Composer autoloader, one class
 * name, two applications.
 */
class ctl_index
{
    public function index()
    {
        throw new Exception('cli run admin index/index', 1);
    }
}
