# Changelog

All notable changes to PlatoPHP are documented in this file. Releases follow
[Semantic Versioning](https://semver.org/).

## 0.1.0 - Unreleased

Initial public release of the PHP 8 framework package.

### Runtime

- HTTP controller dispatch with route action/method declarations and middleware.
- Structured action declarations carry mandatory, validated `methods` and `auth` keys. The shorter
  `'index' => ['GET']` and `'index' => 'GET'` forms, which name methods only, are accepted as well
  and normalize to `auth = optional`. `auth` is `none` (the application authentication callback is skipped),
  `optional` (it runs and may find nobody signed in) or `required` (it must produce an identity).
  Under `optional` and `required` the callback may answer with a `reply` instead. An undeclared
  action, and any question asked before the router has admitted one, count as `required`;
  `make:controller` scaffolds `optional`, so a new controller runs before an authentication
  callback is wired up. `route::action()`, `route::auth_mode()` and `route::requires_auth()` expose
  the matched metadata to integrations.
- A `required` action the callback found nobody for is answered with a framework-rendered 401 and
  the action does not run, the way a failed CSRF check is answered with a 403. Returning a `reply`
  from the callback answers it any other way.
- A callback that breaks its contract -- a value that is neither identity nor reply, or no callback
  configured at all for a `required` action, so that nothing was ever asked -- raises
  `plato\exception\auth_exception` and reports as 500. A declaration the framework cannot read
  reports as 500 as well, rather than as a 404 that reads like a routing mistake, and an `$actions`
  property that is not a public static array reports under its own code rather than blaming one of
  the actions.
- Read-only `route::actions()` metadata for tools that need a controller's declaration without
  duplicating the framework's reflection rules; controller discovery remains application-owned.
- Browser CORS preflights are answered automatically by default after the route and requested
  action method have been validated. Route middleware wraps an empty 204 response while CSRF,
  authentication, encrypted-envelope enforcement and the action are skipped. Ordinary `OPTIONS`
  requests still use action method binding, and `security.cors.preflight = false` binds preflights
  to it as well -- under which a bound write action answers 405 before middleware is reached, which
  is not an answer the browser can act on. All method-bound 405 responses carry the RFC-required
  `Allow` header. `security.cors.max_age` caches the answer, and
  `security.cors.allow_headers` narrows which request headers a preflight may approve -- a request
  naming anything outside the list is not approved in part, and a malformed configured list fails
  closed. Unsupported or malformed requested methods are rejected as preflights rather than
  falling through to an `OPTIONS` action.
- `CLI` is the router's marker for an entry point with no HTTP method, and is not an HTTP method a
  request may claim. `check_action()` stands method binding aside for it and `csrf_verify()` counts
  it among the safe methods, so a request allowed to name it -- through `REQUEST_METHOD: CLI` or an
  envelope carrying it -- would reach a bound, CSRF-protected write action with neither check
  applied.
  Only a route assigned by an entry point, in a process that is not serving HTTP, may name it; it
  is absent from `route::http_methods()`, from what `method_allowed()` accepts under `'*'`, and
  from what a preflight may request.
- Unknown wire methods and unsupported preflight methods reach `check_action()` before they are
  refused, so their 405 can name what the target action allows. An encrypted envelope remains held
  to the known method set while it is decoded; an unknown method there is malformed routing data
  and reports as an invalid route rather than as a 405 without a resource-specific `Allow` value.
- Immutable HTTP replies for JSON, text, HTML, redirects, files, downloads, and streams, including
  `reply::with_body()` for a middleware that rewrites what the action produced.
- An `error_handle` callback in `plato::registry()`, asked by `error_handler::exception_reply()` to
  render a failure raised before there is a middleware pipeline. Routing has to succeed before
  there is a route to look middleware up for, so without the callback an unknown controller or a
  non-routable action is a failure the application cannot answer at all, and leaves as a text/plain
  body naming an internal class. The callback is handed the throwable and the resolved status, answers
  with a reply or with null to keep the built-in response, and runs inside a try/catch so that an
  error page that is itself broken is logged rather than served as nothing -- `resp::restore()`
  puts back what such a callback had queued on the response builder before it threw. It is asked
  for a web request only: a dying command line process has nobody to send a page to, and rendering
  one would run a template engine and its queries on the way out.
- Uncaught failures that resolve to a 4xx are logged as one warning line rather than a stack trace
  at error level. A mistyped url is a client error, not an incident, and reading the offending
  source file off disk to describe one costs more than the entry is worth.
- Request capture for query, form, JSON, XML, cookies, headers, raw bodies, and uploads.
- Parsing an XML request body reports a missing `ext-simplexml` dependency explicitly. Malformed
  XML resolves to an empty body instead, so request error reporting cannot recurse through the
  parser.
- Foreground multiprocess supervision and fork-aware resource ownership.
- Resident socket server contracts under `plato\server`: driver, connection, named server facade,
  and message dispatcher. Protocol neutral by contract -- websocket is the `config/server.php`
  default, and the same seven-method driver serves tcp or a custom framed protocol, provided the
  adapter hands the dispatcher one whole application message rather than a byte stream.
- One request boundary for every resident entry point: a server message and a queue message are
  both served after `plato::reset_request()`, whether or not the work is routed to a controller.
- A registry `reset_handle` invoked after `plato::reset_request()` clears framework state, so a
  resident application can clear request-scoped statics the framework does not own. The profiler
  enable flag is reset at the same boundary.
- Request-scoped elapsed time and peak memory accounting in resident workers, with peak reset on
  PHP 8.2 and later and a non-negative memory delta on every supported version.
- One process identity for every worker group: `plato\worker` answers which of a group's processes
  this one is, and `owns()` shards work across them without a lock. `plato\pool` claims it in the
  child after the fork, and a `plato\server` adapter claims it in each worker it starts.

### Data and services

- MySQL/MariaDB, ClickHouse, and MongoDB drivers with bound query execution.
- Query builder, transactions, replicas, migrations, schema builder, and seeders, including native
  `STRAIGHT_JOIN` compilation.
- Redis, file, Memcached, and in-process cache stores with tags and remember operations.
- Redis-backed distributed locks with owner tokens and atomic release or renewal.
- Redis list, Redis stream, and Kafka queue drivers with retry, delay, and dead-letter handling.
- Local file storage plus a disk contract for external adapters.
- cURL HTTP client with middleware, retries, response values, and concurrent pools.

### Application framework

- CSRF, CORS, IP/country filtering, fixed-window throttling, input validation, HMAC signing,
  and AES-256-GCM binary envelopes.
- `validate` can state the shape of a value, which is what a JSON body needs and a form never does.
  A rule is offered one value at a time and an array field is walked into before any rule runs, so
  the array itself is the one thing an ordinary rule cannot see: on its own, a field declared
  `required|maxlength[3]` accepts `["ok"]` and reaches the application as an array, and a field
  documented as a list accepts a string. `scalar`, `list` and `map` are decided in `_execute()`
  while the value is still whole and are the field's whole verdict, `required` included -- a scalar
  excludes arrays, objects and resources, while a list answers for being a list and what is in it is
  described by naming the elements. `[*]` in a field name is resolved only across a list when the
  rules are set, so `items[*][sku]` becomes `items[0][sku]`, `items[1][sku]`, one name and one error
  per element. Maps are not expanded: application keys such as `*`, `.`, or brackets are not
  losslessly representable as field-name syntax. A field below a field the rule set declares itself
  is not asked when that one arrived null, which is how an optional object holds required properties;
  a nested name whose parents are not declared -- the shape a form posts -- keeps the plain
  per-value behavior. Nested values retain a blank string instead of turning it into null, so
  container shape rules give the same verdict at every depth.
  Adds `validate::CONTAINER_RULES`, the messages of the three rules, and the protected
  `_absent_ancestor()`, `_container()`, `_element_names()`, `_elements()`, `_name_keys()` and
  `_properties()`. Shape and `[*]` expansion belong here rather than in a `validate` subclass owned
  by contract-first API generation: they are not API concerns, and every controller taking a JSON
  body needs them.
- Console kernel, generators, migration commands, queue workers, seed commands, and cron scheduling.
  Scheduler inspection retains invalid entries without output, while lifecycle callbacks let
  an application gate automatic runs and observe start, finish, and skip without replacing the
  built-in commands. Manual `schedule:exec` deliberately bypasses the automatic gate.
- Console failures fall back to the host error log when no CLI stderr stream or `STDERR` constant
  exists, so code shared with a web SAPI cannot fatal while reporting the original failure.
- Optional Smarty 5 facade and built-in template plugins, plus `tpl::decorate()` so the benchmark
  placeholders and the profiler panel also reach an application whose controllers return replies.
  `tpl::output()` applies them from the shutdown handler, on a page nobody has sent -- which the
  documented `return $reply` pattern never produces.
- A built-in `profiler_middleware` that enables profiling in debug mode and decorates completed
  HTML replies without reading JSON, file, or stream bodies. It decorates a reply the action
  returned and one it only prepared with `resp::html()`, so the panel does not depend on which of
  the two forms a controller used.
- The profiler panel is a drawer along the bottom of the window, closed until the launcher in the
  corner is clicked, rather than a block appended to the end of the document. Any back office
  frame fixes a header and a side column over the viewport, under which a panel in the document
  flow is partly unreadable and offers nothing to click to get it out of the way. The open
  flag and the height are classes on `<html>` backed by `localStorage`, so the drawer survives a
  navigation and a host page can give it room with `html.pp-open .content { bottom: var(--pp-height) }`.
  The bar carries the query count, the SQL time, the request time and the memory before anything is
  opened, truncating that summary instead of wrapping it over the controls on a narrow viewport.
  Adds one protected `profiler::_summary()`.
- Panel markup carries classes and is styled by the panel's own stylesheet, rather than by an inline
  `style` attribute on every element it emits, which no application hosting the panel could
  override. Section headings are elements rather than a `<legend>` outside any `<fieldset>`, the
  copy control is styled rather than marked up with class names from a CSS framework the host page
  has no reason to have loaded, a statement keeps the `<code>` element `str::highlight_code()`
  produces, and no horizontal scrollbar is drawn under every value.
- Cookie and session keys in the panel are HTML-escaped. A cookie name is chosen by the client, so
  an unescaped key writes attacker-controlled text into the page.
- SQL keyword emphasis in the panel is one word-bounded pass over the text between tags, in
  `profiler::_emphasise_sql()`. Searching for each keyword in turn, without a word boundary and over
  the markup earlier passes emitted, finds `IN` inside `DISTINCT` and `HAVING` and marks it there
  (`<strong>HAV<strong>IN</strong>G</strong>`), and lets `AS` take the front of `ASC` and leave the
  C behind. Multi-word keywords are matched on both word separators: `highlight_string()` emitted a
  literal `&nbsp;` for a space until PHP 8.3 changed the markup, so accepting only one of the two
  would silently drop `ORDER BY`, `GROUP BY`, `LEFT JOIN`, `NOT IN` and `NOT LIKE` on one side of
  that version line. `RIGHT JOIN`, `INNER JOIN`, `JOIN`, `DELETE` and `SET` are in the list as well.
  Text inside the string-literal spans from `highlight_string()` is left alone, so data containing
  SQL-looking words is not presented as another clause.
- Structured logging, error handling, profiling, events, date, array, string, file, casting,
  pagination, and CLI helpers.
- Every `str::random()` output type draws on PHP's cryptographically secure system randomness. The
  random starting point of `str::unique_id()` uses the same source, although its uniqueness
  guarantee remains the process-local counter documented above.
- Thin PSR-3 and PSR-16 adapters.

### Distribution

- PSR-4 Composer package under the `plato\` namespace for PHP 8.0 through 8.5.
- Architecture, style, static analysis, unit, feature, integration, lowest-dependency, and coverage CI.
- English and Simplified Chinese documentation generated as one bilingual site.
- The installation page states that the `control\` / `middleware\` / `command\` prefixes are a
  single-application convention rather than a framework requirement, and documents the layout a
  repository holding several applications wants: one PSR-4 prefix per application, because
  Composer's map is process wide and a shared `control\` prefix would answer every application
  from whichever directory registered first, plus the per-entry `app_path` and
  `controller_namespace` that keep them apart and the `make:*` stubs that need adjusting under it.
