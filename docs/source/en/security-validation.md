# Security and Validation

## Default boundaries

HTTP CSRF protection is enabled by default. With sessions enabled, provide at least 32 random bytes as `CSRF_SECRET` in `.env`. A cookie-free API authenticated only by Bearer tokens may explicitly set `request.csrf_token_on = false`.

`check_purview_handle` is supplied by the host application. The framework controls when it runs, but defines no users, roles, or permission tables. It runs for actions that declared `auth` as `required` or `optional`, and returns an identity object, null when nobody is signed in, or a `reply` to answer the request itself — see [Routing and Middleware](routing-middleware.md) for what each mode expects. The identity lands in `plato::$auth`. A `required` action the callback found nobody for is answered 401 without running.

CORS, IP/country filtering, and fixed-window throttling use the `security` and `throttle` sections.
Browser preflight is answered automatically by default after its target method is validated; set
`security.cors.preflight = false` to bind every `OPTIONS` request as an ordinary action, and
`security.cors.allow_headers` to narrow which request headers a preflight may approve. Throttling
runs only when added to middleware. The Redis cache driver provides atomic counters.

## Input validation

```php
use plato\http\resp;
use plato\security\validate;

$validator = validate::make($input, [
    'email' => 'required|email',
    'age' => 'integer|min[18]',
    'password' => 'required|minlength[12]|password_strong',
]);

if ( $validator->fails() )
{
    return resp::json(['errors' => $validator->errors()], 422);
}

$data = $validator->validated();
```

Rules may be strings, arrays, or callables. `validate::extend()` registers application rules and default messages are replaceable. Use `validated()` to obtain whitelisted fields; validation and request access remain separate responsibilities.

### The shape of a value: `scalar` / `list` / `map`

A rule is offered one value at a time, and an array field is split into its elements before any rule runs — which is right for a form's `emails[]`, and says nothing about **the array itself**. A JSON body needs to be able to say that:

```php
$validator = validate::make(req::json(), [
    'name'          => 'scalar|required',   // one value, and a list is not one
    'tags'          => 'list',              // a JSON array
    'buyer'         => 'map|required',      // a JSON object
    'buyer[email]'  => 'scalar|email',      // asked only when buyer arrived
    'items[*][sku]' => 'scalar|required',   // every element, named one by one
]);
```

- `scalar`, `list` and `map` are decided while the value is still whole, and are the field's **whole verdict** — `required` included. `scalar` accepts PHP scalar values, not arrays, objects or resources; null remains an absent value for `required` to decide. A list answers for arriving and for being a list; what is in it is described by the elements' own rules. Without `scalar`, a `name` declared `required|maxlength[3]` accepts `["ok"]`: the rules are taken down to the elements one at a time and all of them pass.
- `[*]` is resolved only across a list when the rules are set: `items[*][sku]` becomes `items[0][sku]`, `items[1][sku]` — one field name and one error per element. An absent list asserts nothing about elements, and a map is not expanded because its application keys cannot be represented losslessly as field-name syntax; declare the holding field as `list` to reject that shape.
- A field below a field **the rule set declares itself** is not asked when that one arrived null, which is how an optional object holds required properties. A nested name whose parents are not declared — the shape a form posts — keeps the plain per-value behavior: `contacts[0][email] => required` reports a missing field when nothing was submitted.

## Signatures and encrypted envelopes

`plato\security\sign` creates HMAC signatures over canonical parameters for service-to-service requests. `plato\http\envelope` defines an AES-256-GCM binary envelope for HTTP bodies and resident server messages, including version, compression flag, nonce, and tag.

The envelope does not replace TLS, authentication, authorization, or replay policy. Keys shipped to client code are extractable; HTTPS, authorization, and CSRF rules still apply.

`plato\security\crypt` is the versioned, optionally compressed AES-256-GCM codec used by the
envelope. It does not define RSA or file-encryption formats. Keys must come from protected
environment configuration, never the repository.
