# 请求与响应

## 读取请求

```php
use plato\http\req;

$id = req::get('id', 0, 'int');
$name = req::post('name', '', 'string');
$payload = req::json();
$token = req::headers('Authorization');
$raw = req::raw();
$method = req::method();
```

`get()`、`post()`、`put()`、`patch()`、`delete()`、`json()`、`xml()` 和 `cookie()` 按来源读取。`all()` 返回合并后的请求数据。JSON、表单、XML 和原始 body 由 `Content-Type` 决定，路由不从普通请求参数读取。

## 上传

```php
use plato\http\upload;

if ( upload::exists('avatar') && upload::extension_is('avatar', ['jpg', 'png']) )
{
    upload::move('avatar', '/srv/uploads/avatar.jpg');
}
```

文件名、扩展名和 MIME 都是输入，服务端仍应按业务要求验证内容并生成自己的存储名。

## 构造响应

```php
use plato\http\resp;

return resp::json(['id' => 7]);
return resp::status(201)->header('Location', '/users/7')->json($data);
return resp::text('ok');
return resp::html($html);
return resp::redirect('/login');
return resp::download('/tmp/report.csv', 'report.csv');
```

body 方法返回不可变的 `plato\http\reply`。`plato::run()` 在 HTTP 边界发送它；适配器和测试可调用 `plato::handle()` 取得返回值。常驻 worker 在每次请求前重建请求输入并调用 `plato::reset_request()`。

Cookie 通过 `resp::cookie()` / `forget_cookie()` 写入，通过 `req::cookie()` 读取。Session 使用 PHP 原生存储机制；`registry(['session_start' => true])` 只负责按明确配置启动它。
