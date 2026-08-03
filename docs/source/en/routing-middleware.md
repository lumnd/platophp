# Routing and Middleware

## Routing

The default URL shape is `/{ct}/{ac}`. `plato::run()` parses the path, instantiates `{controller_namespace}\ctl_{ct}`, and calls `{ac}()`.

```php
namespace control;

use plato\http\resp;

class ctl_user
{
    public static array $actions = [
        'show' => [
            'methods' => ['GET'],
            'auth'    => 'none',
        ],
        'profile' => [
            'methods' => ['GET'],
            'auth'    => 'optional',
        ],
        'save' => [
            'methods' => ['POST', 'PUT'],
            'auth'    => 'required',
        ],
    ];

    public function show()
    {
        return resp::json(['id' => 7]);
    }
}
```

When `route.strict_actions = true`, controllers must declare `$actions`. The `route` section also controls `base_path`, `path_suffix`, default controller/action, and method override.

```php
use plato\http\route;

$url = route::url('user', 'show', ['id' => 7]);
$name = route::name();       // user:show
$method = route::method();
$actions = route::actions(ctl_user::class);
```

`route::actions()` returns the controller's public static `$actions` declaration, or null when
there is no usable declaration. It is a read-only metadata API: the application still owns
controller discovery, while `check_action()` remains the authority that applies strict mode,
method binding, and routability checks.

The shorter method-only forms are supported as well:

```php
public static array $actions = [
    'index' => ['GET'],
    'health' => 'GET',
];
```

Both normalize to `auth = optional`, preserving the earlier global authentication callback
behavior. New declarations should use the structured form so their authentication policy is
explicit. Unknown or empty method names and malformed structured declarations report a 500
configuration error.

### Authentication modes

Both keys of a structured declaration are mandatory. `auth` decides whether the application's
`check_purview_handle` callback runs for that action:

| `auth` | Callback | Returning null | `plato::$auth` |
| --- | --- | --- | --- |
| `none` | Not called | — | null |
| `optional` | Called when configured | Accepted, the action still runs | The identity, or null |
| `required` | Must be configured | 401, the action does not run | The identity |

`optional` is what a page that is public but greets a signed-in visitor needs. Under `optional` or
`required`, the callback may return a `reply` — a 401 of your own shape, a redirect to a login page
— instead of an identity, and that reply answers the request without the action running.

A `required` action the callback found nobody for is answered with a framework-rendered 401, the
same way a failed CSRF check is answered with a 403. That is the visitor's state, not a mistake in
the code. Return your own `reply` from the callback to answer it any other way.

Two things are integration errors rather than visitor states: a value that is neither an identity
nor a `reply`, and a `required` action with no callback configured at all — nothing was asked, so
nothing can be concluded about who is here. Both raise `plato\exception\auth_exception` and report
as 500.

Controllers without an `$actions` declaration fall back to reflection when strict actions are
disabled, and their actions require authentication — an action that has said nothing about who may
reach it gets the careful answer. `make:controller` scaffolds `optional` instead, so a new
controller runs before an authentication callback is wired up. A declaration the framework cannot
read is refused outright with a 500 rather than a 404, so it does not read as a routing mistake;
an `$actions` property that is not a public static array is refused the same way, and says so
rather than blaming one of the actions.

CLI and resident server entry points skip authentication whatever the action declared, unless
`cli_auth` is set.

### CORS preflight and method binding

Browser preflight handling is enabled by default with `security.cors.preflight = true`. An
`OPTIONS` request is treated as a preflight only when it also carries `Origin` and
`Access-Control-Request-Method`. The router resolves the normal `ct:ac` target and validates the
requested method against that action's declaration. A mismatch returns 405 with the action's
`Allow` header; `GET` also permits and advertises `HEAD`.

The requested method is matched against the closed list `route::http_methods()` returns. `CLI` is
not on it: that is the router's marker for an entry point with no HTTP method, and `check_action()`
stands method binding aside for it while `csrf_verify()` counts it among the safe methods. A
request cannot name it, on the request line or in a preflight — only an entry point that assigned
the route itself, in a process that is not serving HTTP, may. An unsupported or malformed requested
method is still treated as a preflight candidate and rejected with 405; it never falls through to
an `OPTIONS` action.

After validation, route middleware wraps an empty 204 response. CSRF, authentication, encrypted
envelope enforcement, and the controller action do not run. The response advertises the requested
method, and `security.cors.max_age` (600 seconds by default) tells the browser how long it may
cache the answer. Origin acceptance still follows `security.allow_origin`.

`security.cors.allow_headers` decides which request headers a preflight may approve. Configured
absent, the requested list is echoed back. Name the headers to narrow it:

```php
// app/config/config.php
'security' => [
    'cors' => [
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ],
],
```

Matching is case-insensitive. A request naming anything outside the list is not approved at all
rather than approved in part — a partial approval would let the browser send a request the server
never agreed to. A configured value that is not an array of valid header names also approves
nothing, so a typo cannot widen the policy. The preflight itself still answers 204; the browser is
what then declines to send the real request, which is where that refusal belongs.

An `OPTIONS` request without the two preflight headers remains an ordinary request and must be
allowed by the action declaration. Set `security.cors.preflight = false` to restore that behavior
for all `OPTIONS` requests.

## Middleware

Middleware is configured with `*`, `ct:*`, and `ct:ac` patterns. It runs in configuration order, and the same callable runs only once.

```php
// app/config/config.php
return [
    'middleware' => [
        '*' => ['middleware\\request_id'],
        'admin:*' => ['middleware\\admin_only'],
    ],
];
```

```php
namespace middleware;

class request_id
{
    public function handle(callable $next)
    {
        return $next()->with_header('X-Request-Id', bin2hex(random_bytes(8)));
    }
}
```

Classes with `handle()`, invokable classes, closures, and ordinary callables are accepted. For an
ordinary request, CSRF, authentication, and the action run inside the pipeline. For an automatic
preflight, middleware instead wraps the framework's empty 204 destination; the action and security
checks are not executed.
