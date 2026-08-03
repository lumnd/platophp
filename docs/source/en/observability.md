# Logging and Debugging

## Logging

```php
use plato\log;

log::info('order {id} created', ['id' => 42]);
log::warning('remote service is slow', ['service' => 'billing']);
log::error('payment failed', ['exception' => $exception]);
```

Logging supports PSR-style levels, context interpolation, shared context, and request ids. File output is organized by date and level; CLI output can target stdout or stderr. Open append handles are owned by `plato\runtime` and reacquired after a fork.

With `psr/log` installed, pass `plato\psr\logger` to a library requiring `Psr\Log\LoggerInterface`.

## Error handling

`plato\debug\error_handler` captures PHP errors, unhandled exceptions, and shutdown fatals during registry bootstrap. HTTP receives a structured error reply; CLI writes to stderr and exits non-zero.

Routing failures -- unknown controller, non-routable action, method not allowed -- are raised before the pipeline exists, so middleware cannot reach them: there has to be a route before there is middleware to look up. To render those responses yourself, configure `error_handle` in `plato::registry()`:

```php
'error_handle' => static function (Throwable $e, int $status): ?reply
{
    // Null keeps the framework's own response
    return $status === 404 ? resp::status(404)->html($page) : null;
},
```

The callback receives the throwable and the HTTP status the framework resolved it to, and runs inside a try/catch: an error page that is itself broken is logged as its own incident rather than leaving the request with no answer at all, and whatever it had queued on `resp` before it broke is rolled back. It is asked for a web request only -- a CLI process has nobody to send a page to, so a failing command logs, reports on stderr, and exits without running it.

A failure resolving to a 4xx is logged as one warning line; only a 5xx carries a full trace at error level. A mistyped url is a client error, not an incident.

Debug details are shown when `debug=true` or the client address is included in `security.safe_client_ip`. Disable debug in production and retain exceptions, request ids, and useful context in logs.

## Performance

`plato\debug\benchmark` provides markers plus elapsed time and memory. `plato::app_total()` reports elapsed time and peak memory growth since the current request stamp; resident request boundaries reset the peak on PHP 8.2 and later. The profiler can summarize configuration, routing, input, headers, sessions, and SQL for local debugging. Never expose profiler output to untrusted clients.

The panel is off until `profiler::instance()->enable_profiler()` turns it on. `tpl::output()` appends it, which is the path that flushes `tpl::$output` at shutdown. A controller that `return`s a reply never reaches `output()`; register the framework middleware in `config/config.php` instead. It enables the profiler only in debug mode and decorates a completed `text/html` reply, after the action's `_end` benchmark marks exist.

```php
// config/config.php
use plato\debug\profiler_middleware;

return [
    'middleware' => [
        '*' => [profiler_middleware::class],
    ],
];
```

Both reply forms are decorated: an action that returns `resp::html(...)` and one that calls it
without returning, which `plato::run()` answers from `resp::prepared()`. JSON and other non-HTML
replies pass through unchanged. A file or stream body is not read, so the middleware does not turn
a download into a buffered response. `plato::reset_request()` disables the profiler before the next
resident request.

Debug mode is the only thing that turns the panel on here, which makes the two rendering paths
differ on purpose. With `debug=false` an application that calls `enable_profiler()` by hand still
gets the panel on the `tpl::output()` echo path -- that path reads the flag, not where the flag came
from -- while this middleware leaves the reply alone. Registering it globally therefore cannot
append the panel to a production page.

The panel renders as a drawer along the bottom of the window, closed until the launcher in the
corner is clicked, and its bar carries the query count, the SQL time, the request time and the
memory before anything is opened. On a narrow viewport that summary stays on one line and truncates
before the controls rather than increasing the bar height. Fixed positioning rather than a block at
the end of the document, because a back office frame fixes a header and a side column over the
viewport and a panel in the document flow renders underneath them. The open flag and the height are
classes on `<html>` --
`pp-open` and `pp-tall` -- kept in `localStorage`, so the drawer survives a navigation, and so a
page whose own content is positioned can give the drawer room rather than let it cover the last
rows of a list:

```css
html.pp-open .my-content { bottom: var(--pp-height); }
```

Everything the panel draws is scoped under `#plato_profiler`, and it installs nothing: the
stylesheet and the script arrive with the markup, so there is no asset to add to the host page.
