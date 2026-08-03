# HTTP 客户端

`plato\http\client` 使用 cURL，提供请求值对象、重试策略、中间件和并发池。

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

`get()`、`post()`、`put()`、`delete()` 都转到 `request()`。响应提供 `status()`、`headers()`、`body()`、`json()`、`ok()`、`client_error()`、`server_error()`、`failed()` 和 `attempts()`。

```php
$responses = $client->pool([
    ['method' => 'GET', 'url' => 'https://service-a.example.com/health'],
    ['method' => 'GET', 'url' => 'https://service-b.example.com/health'],
]);
```

TLS 验证默认开启。只有幂等请求和明确可重试状态应自动重试；POST 默认不重试。客户端中间件包裹一次请求，可统一注入 trace header、认证或观测逻辑。
