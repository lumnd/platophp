# 日志与调试

## 日志

```php
use plato\log;

log::info('order {id} created', ['id' => 42]);
log::warning('remote service is slow', ['service' => 'billing']);
log::error('payment failed', ['exception' => $exception]);
```

日志支持 PSR 风格 level、context 插值、共享 context 和 request id。文件输出按日期与 level 组织，CLI 可配置 stdout/stderr。打开的 append handle 由 `plato\runtime` 管理，fork 后重新取得。

安装 `psr/log` 后，`plato\psr\logger` 可交给要求 `Psr\Log\LoggerInterface` 的库。

## 错误处理

`plato\debug\error_handler` 在 registry 引导时捕获 PHP error、未处理异常和 shutdown fatal。HTTP 返回结构化错误响应，CLI 写 stderr 并使用非零退出码。

路由失败（未知控制器、不可路由的 action、方法不允许）发生在管线建立之前，中间件够不到它们：先有路由，才有中间件可查。要自己渲染这类响应，在 `plato::registry()` 里配置 `error_handle`：

```php
'error_handle' => static function (Throwable $e, int $status): ?reply
{
    // 返回 null 表示沿用框架自带的响应
    return $status === 404 ? resp::status(404)->html($page) : null;
},
```

回调拿到异常和框架已解析出的 HTTP 状态，在 try/catch 里执行：错误页自身出错会被单独记一条日志，不会让请求什么都答不出来，它出错前挂在 `resp` 上的东西也会被回滚。它只在 web 请求下被调用——CLI 进程没有客户端可以送页面，失败的命令记日志、写 stderr、退出，不会去跑它。

解析为 4xx 的失败按一行 warning 记录，5xx 才带完整堆栈记到 error——误敲的 URL 是客户端错误，不是事故。

调试详情在 `debug=true` 或客户端地址属于 `security.safe_client_ip` 时显示。生产环境应关闭调试，并通过日志保留 exception、request id 和必要 context。

## 性能

`plato\debug\benchmark` 提供 marker、耗时和内存差值。`plato::app_total()` 报告当前请求 stamp 之后的耗时与内存峰值增长；PHP 8.2 及以上版本会在常驻请求边界重置峰值。profiler 可汇总配置、路由、请求、header、session 和 SQL，用于本地调试；不要把 profiler 输出暴露给不受信任的客户端。

panel 默认关闭，由 `profiler::instance()->enable_profiler()` 打开。它由 `tpl::output()` 追加到页面上——那是 shutdown 时冲刷 `tpl::$output` 的路径。控制器 `return reply` 时走不到 `output()`，此时在 `config/config.php` 里注册框架中间件。它只在 debug 模式开启 profiler，并在 action 的 `_end` benchmark 标记完成后装饰 `text/html` 响应。

```php
// config/config.php
use plato\debug\profiler_middleware;

return [
    'middleware' => [
        '*' => [profiler_middleware::class],
    ],
];
```

两种响应写法都会被装饰：`return resp::html(...)`，以及只调用不 return、由 `plato::run()` 从
`resp::prepared()` 取回的那种。JSON 等非 HTML 响应保持不变；文件或 stream body 不会被读取，
因此下载不会被改成内存缓冲响应。`plato::reset_request()` 会在下一个常驻请求前关闭 profiler。

这里只有 debug 模式能打开 panel，因此两条渲染路径的行为是刻意不同的：`debug=false` 时，应用手动
调用 `enable_profiler()` 仍然能在 `tpl::output()` 的 echo 路径上看到 panel——那条路径只读标志、
不管标志从哪来——而本中间件不装饰响应。所以全局注册它不会把 panel 追加到生产页面上。

panel 渲染成贴在窗口底部的抽屉，默认收起，点右下角的按钮打开；不打开也能从顶栏读到本次请求的 SQL
条数、SQL 耗时、请求总耗时和内存。窄屏下这段摘要保持单行并在控制按钮前截断，不会撑高顶栏。用固定
定位而不是追加在文档流末尾，是因为后台框架普遍把 header 和侧栏固定在视口上，文档流里的 panel 会
渲染在它们下面。开合状态和高度是 `<html>` 上的两个 class——
`pp-open` 和 `pp-tall`——存在 `localStorage` 里，所以翻页不会丢，宿主页面也可以据此给抽屉让位，
免得列表最后几行被压住：

```css
html.pp-open .my-content { bottom: var(--pp-height); }
```

panel 画出来的一切都限定在 `#plato_profiler` 下，并且不需要安装任何东西：样式和脚本跟着标记一起来，
宿主页面不用额外引资源。
