# 安全与校验

## 默认边界

HTTP CSRF 默认开启。启用 session 时应在 `.env` 提供至少 32 byte 随机 `CSRF_SECRET`。无 Cookie、只使用 Bearer Token 的 API 可显式设置 `request.csrf_token_on = false`。

`check_purview_handle` 是宿主应用提供的鉴权回调。框架负责调用时机，不定义用户、角色或权限表。声明 `auth` 为 `required` 或 `optional` 的 action 会触发它；返回身份对象、未登录时返回 null，或返回 `reply` 直接应答请求——各模式的具体要求见[路由与中间件](routing-middleware.md)。身份对象会放进 `plato::$auth`。`required` 的 action 在回调没找到人时直接应答 401，不会执行。

CORS、IP/国家过滤和固定窗口限流由 `security` 与 `throttle` 配置段控制。浏览器预检默认在目标 method
校验通过后自动应答；设置 `security.cors.preflight = false` 可让所有 `OPTIONS` 都按普通 action 绑定，
`security.cors.allow_headers` 则用来收窄预检可以批准的请求头。限流只有加入 middleware 后才执行，
Redis cache driver 提供原子计数。

## 输入校验

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

规则支持字符串、数组和 callable。`validate::extend()` 注册应用规则；默认消息可覆盖。使用 `validated()` 取得白名单字段，不把校验和请求读取混为一件事。

### 值的形状：`scalar` / `list` / `map`

规则逐个值执行，数组字段在任何规则运行之前就被拆成元素分别检查——这对表单的 `emails[]` 是对的，但它对**数组本身**什么都没说。JSON body 需要能说这件事：

```php
$validator = validate::make(req::json(), [
    'name'          => 'scalar|required',   // 单值，list 不是单值
    'tags'          => 'list',              // JSON 数组
    'buyer'         => 'map|required',      // JSON 对象
    'buyer[email]'  => 'scalar|email',      // 只在 buyer 到达时才问
    'items[*][sku]' => 'scalar|required',   // 每个元素，逐个命名
]);
```

- `scalar` / `list` / `map` 在值还完整的时候判定，并且是该字段的**全部结论**——`required` 也包含在内。`scalar` 接受 PHP scalar，不接受数组、对象或 resource；null 仍是交给 `required` 判断的缺失值。列表只回答"到没到达、是不是列表"，里面装什么由元素自己的规则描述。没有 `scalar` 时，声明成 `required|maxlength[3]` 的 `name` 会接受 `["ok"]`：规则被拿去逐个检查元素，全部通过。
- `[*]` 只在设置规则时按实际 list 展开：`items[*][sku]` 变成 `items[0][sku]`、`items[1][sku]`，每个元素一个字段名、一条错误。list 不存在时什么都不断言；map 不展开，因为它的应用数据 key 无法无损表达成字段名语法。要拒绝这种形状，应把承载字段声明成 `list`。
- **规则集自己声明过**的字段若到达为 null，它下面的字段就不再询问——可选对象携带必填属性就是这样表达的。父级未被声明的嵌套名（表单 POST 的形状）仍按逐值规则处理：`contacts[0][email] => required` 在什么都没提交时照样报缺失。

## 签名与加密信封

`plato\security\sign` 对规范化参数生成 HMAC，适合服务间请求签名。`plato\http\envelope` 定义 AES-256-GCM 二进制信封，可用于 HTTP body 与常驻服务 message：版本、压缩标记、nonce 和 tag 都在信封中。

加密信封不是 TLS、身份认证、权限或防重放策略的替代品。客户端持有的 key 可以被提取；服务端仍需 HTTPS、鉴权和 CSRF 策略。

`plato\security\crypt` 是信封使用的版本化 AES-256-GCM codec，可按需压缩；它不定义 RSA 或文件加密
格式。密钥只能来自安全的环境配置，不应写入仓库。
