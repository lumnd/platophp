<?php
/**
 * route: path parsing, the action whitelist, url generation and the crypto entry point.
 */

namespace control;

use plato\exception\route_exception;
use plato\http\route;

// Controllers used by the action whitelist tests. Names are unique across the suite because
// PHPUnit builds every test file in one process.

class ctl_route_base
{
    public function inherited()
    {
        return 'inherited';
    }
}

class ctl_route_open extends ctl_route_base
{
    public function index()
    {
        return 'index';
    }

    public static function statically()
    {
        return 'static';
    }

    protected function secret()
    {
        return 'secret';
    }

    public function _hidden()
    {
        return 'hidden';
    }
}

class ctl_route_declared extends ctl_route_base
{
    public static $actions = [
        'index' => ['methods' => ['GET'], 'auth' => 'none'],
        'del'   => ['methods' => ['POST'], 'auth' => 'required'],
        'item'  => ['methods' => '*', 'auth' => 'optional'],
        'many'  => ['methods' => ['PUT', 'PATCH'], 'auth' => 'none'],
        'short_array'  => ['GET'],
        'short_string' => 'GET',
        'inherited' => ['methods' => ['GET'], 'auth' => 'none'],
        'missing'   => ['methods' => ['GET'], 'auth' => 'none'],
        'secret'    => ['methods' => ['GET'], 'auth' => 'none'],
        'statically' => ['methods' => ['GET'], 'auth' => 'none'],
    ];

    public function index()
    {
        return 'index';
    }

    public function del()
    {
        return 'del';
    }

    public function item()
    {
        return 'item';
    }

    public function many()
    {
        return 'many';
    }

    public function short_array()
    {
        return 'short_array';
    }

    public function short_string()
    {
        return 'short_string';
    }

    public function undeclared()
    {
        return 'undeclared';
    }

    protected function secret()
    {
        return 'secret';
    }

    public static function statically()
    {
        return 'static';
    }
}

/**
 * Controller whose declaration each malformed-declaration case rewrites before calling
 * check_action(). One class rather than nine, because every case is the same shape of mistake.
 */
class ctl_route_malformed
{
    /** @var array<string, mixed> */
    public static $actions = [];

    public function index()
    {
        return 'index';
    }
}

/** Public actions property with a malformed outer value */
class ctl_route_malformed_outer
{
    /** @var mixed */
    public static $actions = 'GET';

    public function helper()
    {
        return 'helper';
    }
}

beforeEach(function () {
    route::reset(true);
    unset(
        $_SERVER['REQUEST_URI'],
        $_SERVER['PATH_INFO'],
        $_SERVER['HTTP_X_ORIGINAL_URL'],
        $_SERVER['HTTP_X_REWRITE_URL'],
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
    );
});

/*
|--------------------------------------------------------------------------
| Path parsing
|--------------------------------------------------------------------------
*/

it('routes the document root to the default route', function () {
    $r = route::resolve('/', 'GET');

    expect($r['ct'])->toBe('index');
    expect($r['ac'])->toBe('index');
    expect($r['source'])->toBe(route::SOURCE_PATH);
});

it('fills in the default action for a single segment', function () {
    $r = route::resolve('/article', 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('index');
});

it('maps two segments onto ct and ac', function () {
    $r = route::resolve('/article/view', 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
    expect($r['segments'])->toBe([]);
});

it('keeps trailing segments out of ct and ac', function () {
    $r = route::resolve('/article/view/10/zh-cn', 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
    expect($r['segments'])->toBe(['10', 'zh-cn']);
});

it('ignores the query string when routing', function () {
    // The whole point of dropping ?ct=&ac= : query and body can no longer name a route
    $r = route::resolve('/article/view?ct=admin&ac=del', 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
});

it('refuses upper case controller and action names', function () {
    route::resolve('/Article/View', 'GET');
})->throws(route_exception::class);

it('refuses a leading underscore in the action', function () {
    route::resolve('/article/_secret', 'GET');
})->throws(route_exception::class);

it('refuses dot segments', function () {
    route::resolve('/article/../admin', 'GET');
})->throws(route_exception::class);

it('refuses an encoded slash instead of splitting on it', function () {
    route::resolve('/article%2Fview', 'GET');
})->throws(route_exception::class);

it('refuses an encoded null byte', function () {
    route::resolve('/article/view%00', 'GET');
})->throws(route_exception::class);

it('refuses a backslash', function () {
    route::resolve('/article\\view', 'GET');
})->throws(route_exception::class);

it('refuses an empty segment rather than collapsing it', function () {
    route::resolve('/article//view', 'GET');
})->throws(route_exception::class);

it('refuses a path over the length limit', function () {
    route::resolve('/article/' . str_repeat('a', 300), 'GET');
})->throws(route_exception::class);

it('refuses more segments than configured', function () {
    route::configure(['max_segments' => 3]);

    route::resolve('/a/b/c/d', 'GET');
})->throws(route_exception::class);

it('refuses a segment that is too long', function () {
    route::resolve('/article/' . str_repeat('a', 40), 'GET');
})->throws(route_exception::class);

it('reports a canonical path for a trailing slash', function () {
    $r = route::resolve('/article/view/', 'GET');

    expect($r['ct'])->toBe('article');
    expect(route::redirect())->toBe('/article/view');
});

it('reports no redirect for an already canonical path', function () {
    route::resolve('/article/view', 'GET');

    expect(route::redirect())->toBe('');
});

it('builds the redirect from validated segments only', function () {
    // A redirect target assembled from raw input would be an open redirect and a header
    // injection; it has to come back out of the parser, not out of REQUEST_URI
    route::resolve('/article/view/10/', 'GET');

    expect(route::redirect())->toBe('/article/view/10');
});

it('never reads X-Original-URL', function () {
    $_SERVER['REQUEST_URI']         = '/';
    $_SERVER['HTTP_X_ORIGINAL_URL'] = '/admin/del';

    $r = route::resolve(null, 'GET');

    expect($r['ct'])->toBe('index');
    expect($r['ac'])->toBe('index');
});

it('reads the path from REQUEST_URI', function () {
    $_SERVER['REQUEST_URI'] = '/article/view?id=1';

    $r = route::resolve(null, 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
});

it('falls back to PATH_INFO when REQUEST_URI is absent', function () {
    $_SERVER['PATH_INFO'] = '/article/view';

    $r = route::resolve(null, 'GET');

    expect($r['ac'])->toBe('view');
});

/*
|--------------------------------------------------------------------------
| base_path and path_suffix
|--------------------------------------------------------------------------
*/

it('strips the configured base path', function () {
    route::configure(['base_path' => '/blog']);

    $r = route::resolve('/blog/article/view', 'GET');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
});

it('refuses a path outside the configured base path', function () {
    route::configure(['base_path' => '/blog']);

    route::resolve('/article/view', 'GET');
})->throws(route_exception::class);

it('strips the configured path suffix', function () {
    route::configure(['path_suffix' => '.html']);

    $r = route::resolve('/article/view.html', 'GET');

    expect($r['ac'])->toBe('view');
});

/*
|--------------------------------------------------------------------------
| Method handling
|--------------------------------------------------------------------------
*/

it('keeps an unknown request method until action binding can produce Allow', function () {
    expect(route::resolve('/article/view', 'TRACE')['method'])->toBe('TRACE');
});

it('reports 405 for a refused method', function () {
    route::resolve('/route_declared/index', 'TRACE');

    try
    {
        route::check_action(ctl_route_declared::class, 'index', 'TRACE');
        $status = 0;
        $allow  = '';
    }
    catch (route_exception $e)
    {
        $status = $e->status();
        $allow  = $e->params()[2] ?? '';
    }

    expect($status)->toBe(405)
        ->and($allow)->toBe('GET, HEAD');
});

it('reports 404 for an unroutable path', function () {
    try
    {
        route::resolve('/Article', 'GET');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
    }

    expect($status)->toBe(404);
});

it('ignores the method override header by default', function () {
    expect(route::detect_method([
        'REQUEST_METHOD'                 => 'POST',
        'HTTP_X_HTTP_METHOD_OVERRIDE'    => 'DELETE',
    ]))->toBe('POST');
});

it('honours the method override header only when it is turned on', function () {
    route::configure(['method_override' => true]);

    expect(route::detect_method([
        'REQUEST_METHOD'                 => 'POST',
        'HTTP_X_HTTP_METHOD_OVERRIDE'    => 'DELETE',
    ]))->toBe('DELETE');
});

it('never lets an override downgrade a request to a csrf exempt method', function () {
    route::configure(['method_override' => true]);

    // The old unconditional override was a csrf bypass exactly here: POST with
    // X-HTTP-Method-Override: HEAD skipped the check while $_POST stayed fully populated
    foreach ( ['GET', 'HEAD', 'OPTIONS', 'CLI'] as $downgrade )
    {
        expect(route::detect_method([
            'REQUEST_METHOD'                 => 'POST',
            'HTTP_X_HTTP_METHOD_OVERRIDE'    => $downgrade,
        ]))->toBe('POST');
    }
});

it('keeps the CLI marker out of the http methods it will bind', function () {
    // check_action() stands method binding aside for the marker and csrf_verify() counts it among
    // the safe methods, so a request that could name it would get both for free
    expect(route::http_methods())->not->toContain('CLI')
        ->and(route::method_allowed('CLI', '*'))->toBeFalse()
        ->and(route::method_allowed('CLI', ['CLI']))->toBeFalse()
        ->and(route::valid_methods(['CLI']))->toBeFalse();
});

it('refuses a request that puts the CLI marker on the request line during action binding', function () {
    $status = 0;
    $allow  = '';

    try
    {
        // The path source reads the method off the request, so the marker arriving here means a
        // client sent it. Under nginx or Apache an unknown method is forwarded to PHP as written
        route::resolve('/article/view', 'CLI');
        route::check_action(ctl_route_open::class, 'index', 'CLI');
    }
    catch (route_exception $e)
    {
        $status = $e->status();
        $allow  = $e->params()[2] ?? '';
    }

    expect($status)->toBe(405)
        ->and($allow)->not->toBe('')
        ->and(route::is_resolved())->toBeTrue();
});

it('lets a command line entry point assign the CLI marker', function () {
    // Same marker, but named by assign() in a process that is not serving http, which is the one
    // place it means what it says
    $route = route::assign('article', 'view', 'CLI');

    expect($route['method'])->toBe('CLI')
        ->and(route::source())->toBe(route::SOURCE_MANUAL);
});

it('never lets an override apply to a method other than POST', function () {
    route::configure(['method_override' => true]);

    expect(route::detect_method([
        'REQUEST_METHOD'                 => 'GET',
        'HTTP_X_HTTP_METHOD_OVERRIDE'    => 'DELETE',
    ]))->toBe('GET');
});

/*
|--------------------------------------------------------------------------
| Action whitelist
|--------------------------------------------------------------------------
*/

it('routes to a method the controller declares itself', function () {
    expect(route::check_action(ctl_route_open::class, 'index', 'GET'))->toBe('index');
});

it('refuses an inherited public method', function () {
    route::check_action(ctl_route_open::class, 'inherited', 'GET');
})->throws(route_exception::class);

it('refuses a static method', function () {
    route::check_action(ctl_route_open::class, 'statically', 'GET');
})->throws(route_exception::class);

it('refuses a protected method with 404 rather than a fatal error', function () {
    try
    {
        route::check_action(ctl_route_open::class, 'secret', 'GET');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
    }

    // 500 here would tell an attacker the method exists, which is how internal helpers get
    // enumerated
    expect($status)->toBe(404);
});

it('refuses an underscore prefixed method', function () {
    route::check_action(ctl_route_open::class, '_hidden', 'GET');
})->throws(route_exception::class);

it('refuses a missing method', function () {
    route::check_action(ctl_route_open::class, 'nope', 'GET');
})->throws(route_exception::class);

it('refuses a case variant of a real method', function () {
    // PHP would happily call index() for 'INDEX'; valid_name rejects it earlier, and the
    // reflection check rejects it again
    route::check_action(ctl_route_open::class, 'Index', 'GET');
})->throws(route_exception::class);

it('honours a declared action list', function () {
    expect(route::check_action(ctl_route_declared::class, 'del', 'POST'))->toBe('del')
        ->and(route::requires_auth())->toBeTrue()
        ->and(route::auth_mode())->toBe(route::AUTH_REQUIRED)
        ->and(route::action())->toBe([
            'methods' => ['POST'],
            'auth'    => 'required',
        ]);
});

it('carries each authentication mode through to the admitted action', function () {
    route::check_action(ctl_route_declared::class, 'index', 'GET');
    expect(route::auth_mode())->toBe(route::AUTH_NONE)
        ->and(route::requires_auth())->toBeFalse();

    // Optional is not "no authentication": it is the callback running and being allowed to
    // answer that nobody is signed in
    route::check_action(ctl_route_declared::class, 'item', 'GET');
    expect(route::auth_mode())->toBe(route::AUTH_OPTIONAL)
        ->and(route::requires_auth())->toBeFalse();
});

it('normalizes both legacy method-only declarations to optional authentication', function (string $action) {
    route::check_action(ctl_route_declared::class, $action, 'GET');

    expect(route::action())->toBe([
        'methods' => $action === 'short_array' ? ['GET'] : 'GET',
        'auth'    => route::AUTH_OPTIONAL,
    ])->and(route::auth_mode())->toBe(route::AUTH_OPTIONAL);
})->with(['short_array', 'short_string']);

it('defaults an undeclared controller action to requiring authentication', function () {
    route::check_action(ctl_route_open::class, 'index', 'GET');

    expect(route::auth_mode())->toBe(route::AUTH_REQUIRED)
        ->and(route::requires_auth())->toBeTrue();
});

it('answers required until an action has been admitted', function () {
    // The fail closed direction: nothing has been decided, so the answer is not "no auth needed"
    expect(route::action())->toBeNull()
        ->and(route::auth_mode())->toBe(route::AUTH_REQUIRED)
        ->and(route::requires_auth())->toBeTrue();

    route::check_action(ctl_route_declared::class, 'index', 'GET');
    route::reset(true);

    expect(route::action())->toBeNull()
        ->and(route::auth_mode())->toBe(route::AUTH_REQUIRED);
});

it('keeps the previous action out of a check that failed', function () {
    route::check_action(ctl_route_declared::class, 'index', 'GET');

    try
    {
        route::check_action(ctl_route_declared::class, 'undeclared', 'GET');
    }
    catch (route_exception $e)
    {
        // The refused action must not have taken over the admitted one's metadata
    }

    expect(route::action()['methods'])->toBe(['GET']);
});

it('refuses a declaration it cannot read', function ($declaration) {
    ctl_route_malformed::$actions = $declaration;

    try
    {
        route::check_action(ctl_route_malformed::class, 'index', 'GET');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
    }

    // 500, not 404: a declaration no request can influence is a programming error, and answering
    // "no such action" would send the developer looking for a routing problem
    expect($status)->toBe(500);
})->with([
    'methods only'              => [['index' => ['methods' => ['GET']]]],
    'auth only'                 => [['index' => ['auth' => 'none']]],
    'an unknown auth mode'      => [['index' => ['methods' => ['GET'], 'auth' => 'maybe']]],
    'auth as a boolean'         => [['index' => ['methods' => ['GET'], 'auth' => true]]],
    'null action metadata'      => [['index' => null]],
    'no methods at all'         => [['index' => ['methods' => [], 'auth' => 'none']]],
    'a null method list'        => [['index' => ['methods' => null, 'auth' => 'none']]],
    'a blank method name'       => [['index' => ['methods' => [''], 'auth' => 'none']]],
    'a non string method'       => [['index' => ['methods' => [200], 'auth' => 'none']]],
    'an unknown method'         => [['index' => ['methods' => ['POTS'], 'auth' => 'none']]],
]);

it('refuses a malformed outer actions declaration instead of falling back to reflection', function () {
    $code = 0;

    try
    {
        route::check_action(ctl_route_malformed_outer::class, 'helper', 'GET');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
        $code   = $e->getCode();
    }

    // Its own code, not the malformed-entry one: the property is the problem, and a message about
    // an action would send the developer looking at the wrong line
    expect($status)->toBe(500)
        ->and($code)->toBe(2012);
});

it('refuses a non public actions property rather than reading past it', function () {
    // hasProperty() sees inherited properties, so a base class using the name for something of
    // its own must not leave every controller under it silently on the reflection fallback
    $controller = new class {
        /** @var array<string, mixed> */
        protected static $actions = [];

        public function helper()
        {
            return 'helper';
        }
    };

    $code = 0;

    try
    {
        route::check_action(get_class($controller), 'helper', 'GET');
    }
    catch (route_exception $e)
    {
        $code = $e->getCode();
    }

    expect($code)->toBe(2012);
});

it('leaves no action metadata behind when the declaration is malformed', function () {
    ctl_route_malformed::$actions = ['index' => ['methods' => ['GET'], 'auth' => 'maybe']];

    try
    {
        route::check_action(ctl_route_malformed::class, 'index', 'GET');
    }
    catch (route_exception $e)
    {
        // expected
    }

    expect(route::action())->toBeNull();
});

it('exposes the action declaration without applying routing policy', function () {
    expect(route::actions(ctl_route_declared::class))->toBe(ctl_route_declared::$actions)
        ->and(route::actions(ctl_route_open::class))->toBeNull()
        ->and(route::actions('control\\ctl_route_missing'))->toBeNull();
});

it('refuses a declared action called with the wrong method', function () {
    $allow = '';

    try
    {
        route::check_action(ctl_route_declared::class, 'del', 'GET');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
        $allow  = $e->params()[2] ?? '';
    }

    expect($status)->toBe(405)
        ->and($allow)->toBe('POST');
});

it('refuses a public method that the declared list omits', function () {
    route::check_action(ctl_route_declared::class, 'undeclared', 'GET');
})->throws(route_exception::class);

it('accepts any method for a wildcard declaration', function () {
    expect(route::check_action(ctl_route_declared::class, 'item', 'DELETE'))->toBe('item');
});

it('accepts a list of declared methods', function () {
    expect(route::check_action(ctl_route_declared::class, 'many', 'PATCH'))->toBe('many');
});

it('lets a declared list opt an inherited public action in', function () {
    expect(route::check_action(ctl_route_declared::class, 'inherited', 'GET'))->toBe('inherited');
});

it('refuses declared actions that cannot be called on the controller', function (string $action) {
    route::check_action(ctl_route_declared::class, $action, 'GET');
})->with(['missing', 'secret', 'statically'])->throws(route_exception::class);

it('lets HEAD through wherever GET is allowed', function () {
    expect(route::check_action(ctl_route_declared::class, 'index', 'HEAD'))->toBe('index');
});

it('refuses every undeclared action under strict_actions', function () {
    route::configure(['strict_actions' => true]);

    route::check_action(ctl_route_open::class, 'index', 'GET');
})->throws(route_exception::class);

it('applies default_methods when no list is declared', function () {
    route::configure(['default_methods' => ['GET']]);

    try
    {
        route::check_action(ctl_route_open::class, 'index', 'POST');
        $status = 0;
    }
    catch (route_exception $e)
    {
        $status = $e->status();
    }

    expect($status)->toBe(405);
});

/*
|--------------------------------------------------------------------------
| Url generation
|--------------------------------------------------------------------------
*/

it('builds the root url for the default route', function () {
    expect(route::url('index', 'index'))->toBe('/');
});

it('omits the default action', function () {
    expect(route::url('article'))->toBe('/article');
});

it('builds a two segment url', function () {
    expect(route::url('article', 'view'))->toBe('/article/view');
});

it('appends query parameters', function () {
    expect(route::url('article', 'view', ['id' => 10]))->toBe('/article/view?id=10');
});

it('appends path segments', function () {
    expect(route::url('article', 'view', [], ['10', 'zh-cn']))->toBe('/article/view/10/zh-cn');
});

it('keeps the action when segments follow it', function () {
    expect(route::url('article', 'index', [], ['10']))->toBe('/article/index/10');
});

it('applies base_path and path_suffix when generating', function () {
    route::configure(['base_path' => '/blog', 'path_suffix' => '.html']);

    expect(route::url('article', 'view'))->toBe('/blog/article/view.html');
});

it('refuses to build a url that would not parse back', function () {
    route::url('Article', 'view');
})->throws(route_exception::class);

it('refuses to build a url with an invalid segment', function () {
    route::url('article', 'view', [], ['../etc/passwd']);
})->throws(route_exception::class);

it('round trips generated urls back through the parser', function () {
    foreach ( [['index', 'index', []], ['article', 'index', []], ['article', 'view', ['10']]] as $case )
    {
        [$ct, $ac, $segments] = $case;

        $r = route::resolve(route::url($ct, $ac, [], $segments), 'GET');

        expect($r['ct'])->toBe($ct);
        expect($r['ac'])->toBe($ac);
        expect($r['segments'])->toBe($segments);
    }
});

/*
|--------------------------------------------------------------------------
| Crypto source
|--------------------------------------------------------------------------
*/

it('takes the route from the envelope at the crypto entry', function () {
    route::register_crypto_resolver(function () {
        return ['ct' => 'article', 'ac' => 'edit', 'method' => 'PATCH'];
    });

    $r = route::resolve('/', 'POST');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('edit');
    expect($r['method'])->toBe('PATCH');
    expect($r['source'])->toBe(route::SOURCE_CRYPTO);
});

it('validates envelope names exactly as it validates a path', function () {
    route::register_crypto_resolver(function () {
        return ['ct' => '../admin', 'ac' => 'del', 'method' => 'POST'];
    });

    route::resolve('/', 'POST');
})->throws(route_exception::class);

it('refuses an envelope naming an upper case action', function () {
    route::register_crypto_resolver(function () {
        return ['ct' => 'article', 'ac' => 'Edit', 'method' => 'POST'];
    });

    route::resolve('/', 'POST');
})->throws(route_exception::class);

it('refuses an envelope carrying an array in place of a name', function () {
    // Invalid route component types are rejected at the route boundary.
    route::register_crypto_resolver(function () {
        return ['ct' => ['article'], 'ac' => 'edit', 'method' => 'POST'];
    });

    route::resolve('/', 'POST');
})->throws(route_exception::class);

it('serves the default route when the root carries no envelope', function () {
    route::register_crypto_resolver(function () {
        return null;
    });

    $r = route::resolve('/', 'GET');

    expect($r['ct'])->toBe('index');
    expect($r['source'])->toBe(route::SOURCE_PATH);
});

it('refuses a dedicated crypto entry without a valid envelope', function () {
    route::configure(['crypto_entry' => '/api']);
    route::register_crypto_resolver(function () {
        return null;
    });

    route::resolve('/api', 'POST');
})->throws(route_exception::class);

it('never consults the envelope when the path carries a route', function () {
    $called = false;

    route::register_crypto_resolver(function () use (&$called) {
        $called = true;
        return ['ct' => 'admin', 'ac' => 'del', 'method' => 'POST'];
    });

    $r = route::resolve('/article/view', 'POST');

    expect($called)->toBeFalse();
    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
});

it('ignores the crypto source when it is disabled', function () {
    route::configure(['crypto_entry' => '']);
    route::register_crypto_resolver(function () {
        return ['ct' => 'admin', 'ac' => 'del', 'method' => 'POST'];
    });

    $r = route::resolve('/', 'POST');

    expect($r['ct'])->toBe('index');
    expect($r['source'])->toBe(route::SOURCE_PATH);
});

/*
|--------------------------------------------------------------------------
| Manual assignment, for CLI and WebSocket entry points
|--------------------------------------------------------------------------
*/

it('assigns a route directly', function () {
    $r = route::assign('article', 'view', 'CLI');

    expect($r['ct'])->toBe('article');
    expect($r['ac'])->toBe('view');
    expect($r['source'])->toBe(route::SOURCE_MANUAL);
});

it('validates a directly assigned route', function () {
    route::assign('../admin', 'del', 'CLI');
})->throws(route_exception::class);

it('refuses an array as a directly assigned controller', function () {
    route::assign(['article'], 'view', 'CLI');
})->throws(route_exception::class);

/*
|--------------------------------------------------------------------------
| Route identity helpers
|--------------------------------------------------------------------------
*/

it('names the resolved route as ct:ac', function () {
    route::resolve('/article/view', 'GET');

    expect(route::name())->toBe('article:view');
});

it('matches exact and wildcard route patterns', function () {
    route::resolve('/article/view', 'GET');

    expect(route::matches(['article:view']))->toBeTrue();
    expect(route::matches(['article:*']))->toBeTrue();
    expect(route::matches(['article:edit']))->toBeFalse();
    expect(route::matches(['admin:*']))->toBeFalse();
});

it('answers whether the resolved route requires an envelope', function () {
    route::configure(['crypto_required' => ['article:edit']]);

    route::resolve('/article/edit', 'POST');
    expect(route::crypto_required())->toBeTrue();

    route::configure(['crypto_required' => ['article:edit']]);
    route::resolve('/article/view', 'GET');
    expect(route::crypto_required())->toBeFalse();
});

it('forgets the route on reset, for long running workers', function () {
    route::resolve('/article/view', 'GET');
    expect(route::is_resolved())->toBeTrue();

    route::reset();

    expect(route::is_resolved())->toBeFalse();
    expect(route::ct())->toBe('');
});
