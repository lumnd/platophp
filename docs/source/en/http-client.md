# HTTP Client

`plato\http\client` uses cURL and provides response values, retry policy, middleware, and concurrent pools.

```php
use plato\http\client;

$client = new client([
    'timeout' => 5,
    'connect_timeout' => 2,
    'retries' => 2,
]);

$response = $client->get('https://api.example.com/users/7', [
    'headers' => ['Accept' => 'application/json'],
]);

if ( $response->ok() )
{
    $user = $response->json();
}
```

`get()`, `post()`, `put()`, and `delete()` delegate to `request()`. Responses expose `status()`, `headers()`, `body()`, `json()`, `ok()`, `client_error()`, `server_error()`, `failed()`, and `attempts()`.

```php
$responses = $client->pool([
    ['method' => 'GET', 'url' => 'https://service-a.example.com/health'],
    ['method' => 'GET', 'url' => 'https://service-b.example.com/health'],
]);
```

TLS verification is enabled by default. Automatic retries should be limited to idempotent requests and explicit retryable statuses; POST is not retried by default. Client middleware wraps one request and can add trace headers, authentication, or instrumentation.
