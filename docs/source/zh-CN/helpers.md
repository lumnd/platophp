# 辅助工具

这些类不承载业务语义，可在框架服务中直接组合。

## 数组与字符串

`plato\arr` 提供点号路径 `get/set/del/key_exists`、递归 merge，以及行列表的 `group_by()`、`pluck()`、稳定多键 `sort()` 和 `tree()`。

`plato\str` 提供格式判断、安全随机串、19 位数字 ID、占位替换、Unicode 打码、字节格式化和稳定分桶。真正的唯一性必须由数据库唯一索引保证。

## 日期

```php
use plato\date;

$utc = date::convert('2026-07-31 09:00:00', 'UTC', null, 'Asia/Taipei');
$range = date::month_range('2026-07', 'Asia/Taipei');
$valid = date::valid('2026-02-28', 'Y-m-d');
```

时间戳表示一个瞬间，字符串表示指定时区中的挂钟时间。`date` 的显示时区与 PHP 进程默认时区分开配置。

## 其他

- `plato\cast`：请求值的基础类型转换
- `plato\file`：扩展名、路径存在、写文件和图片 URL
- `plato\paginator::meta()`：不依赖请求与 HTML 的分页元数据
- `plato\cli`：终端输入输出、颜色、option 与标准流
- `plato\config`：配置读取与点号路径

辅助类只解决可复用机制。电话号码、金额、业务 ID、语言字段和领域树等规则应留在宿主应用。
