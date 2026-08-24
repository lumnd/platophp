# 路由与中间件

## 路由

默认 URL 是 `/{ct}/{ac}`。`plato::run()` 解析路径，实例化 `{controller_namespace}\ctl_{ct}`，再调用 `{ac}()`。

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

`route.strict_actions = true` 时控制器必须声明 `$actions`。`base_path`、`path_suffix`、默认 controller/action 和 method override 都由 `route` 配置段控制。

```php
use plato\http\route;

$url = route::url('user', 'show', ['id' => 7]);
$name = route::name();       // user:show
$method = route::method();
$actions = route::actions(ctl_user::class);
```

`route::actions()` 返回控制器公开静态 `$actions` 的声明；不存在可用声明时返回 null。这是只读元数据
接口：控制器发现仍由应用负责，strict mode、HTTP method 绑定与方法是否可路由仍以
`check_action()` 的判断为准。

只声明 method 的两种简写同样接受：

```php
public static array $actions = [
    'index' => ['GET'],
    'health' => 'GET',
];
```

两者都会归一化为 `auth = optional`，以保留原先全局鉴权回调的行为。新增声明应使用结构化写法，
明确写出鉴权策略。未知或空的 method，以及不完整的结构化声明，都会作为配置错误报 500。

### 鉴权模式

结构化声明里的两个键都是必填。`auth` 决定应用的 `check_purview_handle` 回调是否为该 action 执行：

| `auth` | 回调 | 返回 null | `plato::$auth` |
| --- | --- | --- | --- |
| `none` | 不调用 | — | null |
| `optional` | 配置了就调用 | 接受，action 照常执行 | 身份对象，或 null |
| `required` | 必须配置 | 401，action 不执行 | 身份对象 |

`optional` 就是给「页面公开，但登录了要认得你」这类场景用的。在 `optional` 或 `required` 下，
回调都可以返回 `reply`（自定形态的 401、跳登录页）代替身份对象，该 reply 直接作为响应，action
不会执行。

`required` 的 action 在回调没找到人时，由框架渲染一个 401 作答，和 CSRF 校验失败作答 403 是同一
套路：这是访客的状态，不是代码写错。想换成别的应答，就从回调里返回自己的 `reply`。

两者都走 `resp::error()`，未配置 `error_handle` 回调时未捕获异常的应答也走它：请求要 JSON 就回
JSON，否则回 `text/plain`。框架不渲染自己的 HTML 错误页——它没有你的模板可用。

只有两种情况算集成错误：回调返回了既非身份对象也非 `reply` 的值，以及 `required` 却根本没配置回
调——什么都没问过，也就无从判断来的是谁。这两类抛 `plato\exception\auth_exception`，报 500。

关闭 strict actions 且控制器没有声明 `$actions` 时，走反射回退，这些 action 一律需要鉴权：没有
声明过谁可以访问的 action，按最保守的方式处理。`make:controller` 生成的模板则用 `optional`，好让
新建的控制器在鉴权回调接上之前就能跑起来。框架读不懂的声明会直接拒绝并报 500 而不是 404，免得
看起来像路由配错了；`$actions` 本身不是 public static 数组时同样拒绝，并且会明说是属性的问题，
而不是赖到某个 action 头上。

CLI 与常驻服务入口不论 action 怎么声明都跳过鉴权，除非设置了 `cli_auth`。

### CORS 预检与 method 绑定

浏览器预检默认由 `security.cors.preflight = true` 自动处理。只有同时带 `Origin` 和
`Access-Control-Request-Method` 的 `OPTIONS` 才会被当作预检。路由先解析正常的 `ct:ac`，再用请求
头里的目标 method 校验 action 声明；不匹配时返回 405，并带上该 action 的 `Allow` 头。声明 `GET`
也会允许并公布 `HEAD`。

目标 method 只在 `route::http_methods()` 这个封闭列表里匹配。`CLI` 不在其中：那是路由器给「没有
HTTP method 的入口」用的标记，`check_action()` 遇到它会跳过 method 绑定，`csrf_verify()` 又把它
算作安全 method。请求无论写在请求行还是预检头里都不能声称自己是它——只有在不对外提供 HTTP 服务的
进程里、由入口自己 assign 的路由才可以。不支持或格式错误的目标 method 仍作为预检候选进入校验并
返回 405，绝不会退回普通 `OPTIONS` action。

校验通过后，route middleware 包裹框架生成的空 204 响应。CSRF、鉴权、加密信封要求和 controller
action 都不会执行。响应会公布目标 method，`security.cors.max_age`（默认 600 秒）告诉浏览器这个
答复可以缓存多久；origin 是否允许仍由 `security.allow_origin` 决定。

预检可以批准哪些请求头由 `security.cors.allow_headers` 决定。不配置时原样回显请求的列表，配置了
就按名单收窄：

```php
// app/config/config.php
'security' => [
    'cors' => [
        'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ],
],
```

匹配不分大小写。请求里出现名单外的头时，不是批准其中一部分，而是一个都不批准——批准一部分等于让
浏览器发出一个服务端从没同意过的请求。配置值不是由有效 header 名组成的数组时同样一个都不批准，
避免配置拼错反而放宽策略。预检本身仍然回 204，接下来拒绝发出真实请求的是浏览器，这个拒绝本来就
该发生在那里。

不带这两个预检请求头的 `OPTIONS` 仍是普通请求，必须由 action 声明允许。设置
`security.cors.preflight = false` 可让所有 `OPTIONS` 恢复这种旧行为。

## 中间件

中间件按 `*`、`ct:*`、`ct:ac` 三种模式配置，顺序与配置一致，同一个 callable 只执行一次。

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

类的 `handle()`、`__invoke()`、闭包和普通 callable 都可使用。普通请求的 CSRF、鉴权与 action 在
管线内执行；自动预检时，中间件改为包裹框架的空 204 destination，不执行 action 与安全检查。
