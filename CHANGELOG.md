# Changelog

All notable changes to PlatoPHP are documented in this file. Releases follow
[Semantic Versioning](https://semver.org/).

## 0.2.0 - 2026-08-24

### The engine contract says the same thing on both drivers

Each of these is a place where the contract was written down and only one driver honoured it, which
is the failure mode a two-driver split exists to expose.

- `plato\tpl::engine()` caches the driver only once `configure()` has returned. It cached first, so a
  driver rejecting its settings threw on the first call and then answered every later one with an
  instance still holding its defaults -- a wrong template directory rendering quietly instead of an
  error. Building the driver again costs nothing: the contract forbids its constructor from touching
  the filesystem or building its engine.
- `plato\view\native::configure()` drops the assigned variables. The Smarty driver already did, as a
  consequence of dropping the instance they lived in, and a behaviour a driver cannot avoid is one
  the interface has to state -- `plato\view\engine::configure()` now does.
- The Smarty driver decides "the application assigned this already" by the name being present rather
  than by its value being non-null. `getTemplateVars($name)` answers null for both a name nobody
  assigned and a name deliberately assigned null, so an application assigning `app_name => null` had
  the ambient default written over it while the plain PHP driver, working on array keys, kept it.

### Flaky feature tests, made deterministic

None of them was a flaky assertion about a real race; they were tests that measured wall-clock time,
or inherited state they did not control. What they hid is worth more than what they proved: a suite
that goes red at random is one people re-run rather than read.

- `queueStreamTest` no longer sleeps. The delayed-message case pushed with `delay => 1` and asserted
  "not due yet" -- but `push_delay()` scores the entry `time() + $delay` and the migrator asks for
  everything scored at or below `time()`, both at whole-second resolution, so a push landing near
  the end of a second was already due by the next statement. It is now two cases: one holds a
  message back a minute and checks it does not move, the other rewrites the due score into the past
  and checks it does. The takeover case slept 20ms against a 1ms `claim_idle_ms`; it now hands the
  entry to another consumer name with `XCLAIM ... IDLE`, which ages it 5 seconds in one command --
  and makes the case mean its name, since `_consumer()` is `hostname:pid` and the two pops were
  otherwise the same consumer reclaiming its own entry. The file lost a second of runtime with the
  `sleep()`.
- `httpClientTest` starts from an empty state directory. The counter files that decide how many times
  the far end fails live in a path that does not vary per process, and `afterAll` only removes them
  when the suite finished normally -- so a killed run left every retry case talking to a far end that
  had used up its failures, and the `Retry-After` case measured no wait at all. `afterAll` also
  checks that the pid it is about to kill is still the server this run started.
- **A duration is read from `hrtime()`, not from `microtime()`.** Five cases across four files
  asserted that a wait really happened by subtracting two wall-clock readings, and a wall clock is
  not a duration source: the one the suite runs against is stepped by its time daemon, and a
  correction of about 170ms landing inside the second being measured made the `Retry-After` case
  report 0.83s for a sleep that had lasted a second -- roughly one run in six, which is what was
  left of that case's flakiness after the state directory was dealt with. A monotonic reading
  cannot be walked back. That case now also asserts `attempts()`, which is what it is really
  claiming -- that the header was honoured over the backoff -- and which holds whatever the clock
  does; the elapsed time only tells the two wait lengths apart.

### The migration toolchain is covered against a real server

`migrate`, `migrate:rollback`, `db:seed` and the schema builder were asserted only on the SQL they
compile. The one case that booted the console against the fixture application accepted a connection
error as a pass -- "either the status comes back, or the database is unreachable" -- so the suite
went green on a machine with no MySQL at all, and the workflow's MySQL service was started for
nothing.

- `tests/Feature/migrationMysqlTest.php` runs every verb through `bin/plato` as a subprocess against
  the service and reads the result back through `schema::has_table()` / `has_column()` and
  `db::table()`: a created table has the columns the blueprint declared, a second run migrates
  nothing, `migrate:rollback` reverses the last batch and only the last batch -- including the
  `ALTER` a later migration made to an earlier migration's table -- a seeder run twice leaves the
  same rows, and a migration that throws fails the run instead of being recorded as applied.
- `migrationCliTest.php` requires the bootstrap case to end in exit code 0. It is the case that
  used to accept the connection error.
- The workflow waits for `platophptest` rather than for `mysqladmin ping`: the entrypoint answers a
  ping while it is still creating `MYSQL_DATABASE`, and every case above connects to that database
  by name.

### Public API, settled before 1.0

Three decisions that semantic versioning would otherwise hold until a major release. All three are
breaking; all three are recorded in `tests/tools/api-snapshot.txt`.

- **`plato\tpl` is a facade over a driver contract.** `plato\view\engine` is six calls --
  `configure()`, `config()`, `assign()`, `exists()`, `fetch()`, `clear()` -- and `template.driver`
  names the class that answers them. `plato\tpl` reads that one key and passes the rest of the
  section to the driver whole, so a Smarty delimiter is no longer part of the facade's vocabulary
  and a different engine names a different set of settings. `tpl::instance()`, which returned a
  `Smarty`, is now `tpl::engine()`, which returns an `engine`; `plato\view\smarty::raw()` is the way
  back to the Smarty instance for the things the contract deliberately does not cover.
  `tpl::$config`, `$template_dir`, `$compile_dir`, `$cache_dir`, `DEFAULT_CONFIG` and
  `PLUGIN_TYPES` are gone from the facade -- the three directories are the `template_dir`,
  `compile_dir` and `cache_dir` settings, and the rest belong to `plato\view\smarty`. The facade
  gained the axis A trio (`config()`, `configure()`, `reset_config()`) and now owns the `template`
  section outright.
- **`plato\view\native` renders plain PHP templates**, with no third-party package at all. It exists
  because a contract with a single implementation is a guess about what varies: it and the Smarty
  driver disagree about compilation, delimiters, plugins and caching, and what survived that
  disagreement is the contract above. Templates escape through `$this->e()` and render partials
  through `$this->fetch()`; a name that climbs out of the template directory is refused rather than
  included.
- **Both drivers assign `app_name`, `request` and `clear_cache` per render** rather than once when
  the engine is built. The Smarty driver assigned them at build time and `clear()` took them away
  with everything else, so a resident worker rendered its second request with the first request's
  input and cache buster, and every request after the first boundary with none of the three. A name
  the application already assigned is left alone, which is what the build-time ordering used to
  guarantee.
- **`plato\view\msgbox` is gone**, and with it the `plato\view` namespace's only occupant before the
  contract moved in. `show()` -- a message page with a redirect -- had no caller in this package and
  is an application's design decision rather than a framework capability. `error()` was the
  framework's own 401 and 403, and answered HTML unconditionally: a JSON client that failed a CSRF
  check got a document it could not decode, while an uncaught exception at the same moment
  negotiated on Accept. Both now go through the new `resp::error(int $status, ?string $detail =
  null)`, which is also what `error_handler::_default_reply()` builds, so 401, 403, 404 and 500
  answer the same way. The framework renders no HTML error page of its own; it has no template of
  the application's to render one with.
- **`arr::filter_value()` is gone.** It had no caller outside its own test.

### Process and request safety

Found by reviewing the two boundaries against each other. The fork boundary held up; these are the
places where the request boundary of a resident worker stopped short, and one where the process
boundary was defeated from outside the registry.

- `plato::reset_request()` now rolls back a transaction the last request left open, through the new
  `db::discard_transactions()` / `connection::discard_transactions()`, and warns with the connection
  name and the depth it was left at. A php-fpm process ends with the request and the server rolls
  back what nobody committed; a resident worker keeps its socket, so an action that called `begin()`
  and then threw left the *next* message inside its transaction -- committing writes nobody asked for
  when that one committed, or holding the row locks until the worker exited. The warning is written
  before the log context is cleared, so it carries the request id of whoever left it open. A rollback
  that fails disconnects the connection instead of propagating.
- `plato::reset_request()` clears the shared log context through the new `log::reset()`, then
  `restamp()` issues a fresh `rid`. The keys `log::context()` collects -- a user id, a tenant -- were
  request state that nothing cleared, so one client's lines went on carrying the identity of the
  client before it. Context that belongs to the process rather than the request goes back on through
  the registry `reset_handle`.
- `plato\pool` releases the registry before **every** fork rather than only before the first. A
  `notify` callback that logs, which is the obvious thing to pass it, put a handle back in the
  master's registry after the first fork, and every worker forked to refill a slot then held its
  parent's descriptor open for the rest of its life.
- `plato\queue\worker` guards its signal handlers with the runtime epoch instead of a bool. A fork
  copies the flag along with the handlers, and the handlers a child inherits are its parent's -- under
  `pool` they set `pool::$_stop`, not the flag the consume loop reads. A child that took the early
  return ignored SIGTERM and was SIGKILLed mid-message when the grace period ran out.
- Persistent connections are the one thing the process boundary cannot manage: `PDO::ATTR_PERSISTENT`
  and phpredis' `pconnect()` keep the socket in the extension's own pool, where `plato\runtime` cannot
  release it, so a forked worker is handed its parent's socket. Both drivers now warn once per
  connection when the setting is on inside a worker group, and the two config keys, `pool`'s fork
  contract and the architecture documentation say so.
- `runtime::flush()` and `console\schedule::_take_lock()` document the other edge of the registry:
  `flush()` runs the closers, and the closer of a file lock releases the lock. `TODO.md` carries the
  open question of whether that deserves a second entry kind.
- `check_architecture.php` fixes two stale claims of its own: `src/log.php` was exempt from the
  resource rule for "closing the handle in the same call", which it stopped doing when the append
  handles moved into the registry -- the exemption is gone and the rule now covers it -- and
  `CONFIG_RESET` named a `log::reset()` that did not exist, where the name now means the request
  scoped reset above.

### Configuration

- `.env` is a source of defaults again rather than an override: `plato::registry()` no longer
  replaces a variable the process was started with. It wrote every key of the file into `$_ENV`
  unconditionally, so `DB_PASSWORD` committed to a repository beat the one a container, a CI job or a
  systemd unit injected -- the opposite of the direction deployments inject from, and the reason
  passing a credential to the process appeared to do nothing at all. `getenv()` is consulted
  alongside `$_ENV`, because `variables_order` decides whether the environment reaches `$_ENV`, and a
  variable set to the empty string counts as set. A deployment that relied on the file winning has to
  stop exporting the variable it wants the file to decide.

### Queue

- `plato\queue\stream` decides whether it may send `XAUTOCLAIM` from the server version rather than
  by sending one and reading the answer. The command exists from redis 6.2, but its reply grew a
  third element in 7.0 and phpredis 6 reads a fixed three: against 6.2 the extension waits for the
  rest of a reply the server has already finished sending, so every `pop()` stalled until
  `default_socket_timeout` -- a minute each, and forever wherever that is disabled -- before the read
  error let the driver fall back. Redis 5 was safe only by accident, rejecting the command outright,
  and CI ran redis 7, which left 6.2 the one version nothing covered. The tests now run against 6.2
  as well.

### Installation, documented as delivered

- An installed package is not a checkout of this repository, and the documentation had never said so.
  `composer require` downloads a dist archive built by `git archive`, which honours `export-ignore`:
  `docs/`, `tests/`, the workflows and the development configuration are absent, so anyone looking
  for the documentation or the suite under `vendor/lumnd/platophp` finds neither. Both READMEs and
  both installation pages now state what a dist install contains and that `--prefer-source`, or a
  clone, is what yields the complete repository.

## 0.1.1 - 2026-08-06

### Coding standard

- The standard moved out of this repository into
  [`lumnd/plato-coding-standard`](https://github.com/lumnd/plato-coding-standard), installed as a
  dev dependency and referenced by name from `phpcs.xml`, which now holds only which directories to
  check. It had been pasted into each repository that shares the convention, and the copies drifted.
- The package excludes the same PSR-12 sniffs the pasted copy did and adds the sniff enforcing the
  inverse rule, so a member written the PSR-12 way is now an error rather than a second legal
  spelling. It also checks the Allman brace on closures, which the old configuration did not.
- Bringing the tree under it changed 471 places. 428 were formatting fixes applied by `phpcbf`: one
  space inside control-structure parentheses, and closure braces onto their own line.

### Removed and renamed

None of these had a caller anywhere in the framework, its tests, or the documentation. The public
API snapshot records each one.

- `plato\debug\error_handler`'s five `$_debug_*` statics -- `$_debug_safe_ip`, `$_debug_error_msg`,
  `$_debug_mt_time`, `$_debug_mt_info` and `$_debug_errortype` -- were public but read and written
  only by that class, and are now private, which is what the underscore already claimed.
- `plato\security\validate::_empty()` became `is_empty()`, matching the `plato\security\rules`
  method it forwards to. It was never reachable as a rule name: `RULES` is an allowlist and did not
  contain it.
- Private and protected properties took the leading underscore the convention requires.
  `plato\cli::$STDOUT` and `$STDERR` became `$_stdout` and `$_stderr`; `plato\cli::$foreground_colors`
  and `$background_colors`, `plato\http\rewrite::$is_load` and
  `plato\exception\plato_exception::$params` gained it, along with the private state of
  `plato\config`, `plato\log`, `plato\arr`, `plato\str`, `plato\event` and the three queue drivers.
- The PSR-16 adapter's `getMultiple()`, `setMultiple()` and `deleteMultiple()` keep their camelCase,
  now with a `phpcs:ignore` naming `CacheInterface` as the reason rather than relying on the sniff
  not to look.

## 0.1.0 - 2026-08-03

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
