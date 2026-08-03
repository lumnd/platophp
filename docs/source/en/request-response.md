# Requests and Responses

## Reading a request

```php
use plato\http\req;

$id = req::get('id', 0, 'int');
$name = req::post('name', '', 'string');
$payload = req::json();
$token = req::headers('Authorization');
$raw = req::raw();
$method = req::method();
```

`get()`, `post()`, `put()`, `patch()`, `delete()`, `json()`, `xml()`, and `cookie()` read a specific source. `all()` returns merged input. JSON, form, XML, and raw bodies are selected by `Content-Type`; routing is not read from ordinary request parameters.

## Uploads

```php
use plato\http\upload;

if ( upload::exists('avatar') && upload::extension_is('avatar', ['jpg', 'png']) )
{
    upload::move('avatar', '/srv/uploads/avatar.jpg');
}
```

Names, extensions, and MIME types are input. The server must still validate content for the use case and generate its own storage name.

## Building a response

```php
use plato\http\resp;

return resp::json(['id' => 7]);
return resp::status(201)->header('Location', '/users/7')->json($data);
return resp::text('ok');
return resp::html($html);
return resp::redirect('/login');
return resp::download('/tmp/report.csv', 'report.csv');
```

Body methods return immutable `plato\http\reply` values. `plato::run()` emits the reply at the HTTP boundary; adapters and tests use `plato::handle()` to obtain it. A resident worker rebuilds request input and calls `plato::reset_request()` before every request.

Write cookies through `resp::cookie()` / `forget_cookie()` and read them through `req::cookie()`. Sessions use PHP's native storage mechanism; `registry(['session_start' => true])` only starts one when explicitly configured.
