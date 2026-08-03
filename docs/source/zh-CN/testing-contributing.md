# 测试与贡献

## 公开维护规范

[`CONTRIBUTING.md`](https://github.com/lumnd/platophp/blob/main/CONTRIBUTING.md) 是完整且受 Git 跟踪的
维护契约，涵盖框架边界、编码规范、初始化、进程资源、测试、文档和兼容性。贡献者不应依赖本地 AI
协作说明或未发布的任务清单。

## 验证环境

所有 PHP 验证都在 Docker 开发容器中运行。宿主机安装的扩展、服务名和进程权限可能不同，因此其 PHP
运行结果不作为验收依据。

```bash
docker compose exec -T -e REDIS_HOST=redis6 php82 sh -lc \
  'cd /data/web/platophp && composer test'

docker compose exec -T php82 sh -lc \
  'cd /data/web/platophp && composer check:architecture && composer style && composer analyse'
```

主要命令：

| 命令 | 用途 |
| --- | --- |
| `composer test` | Unit 与 Feature 测试 |
| `composer test:unit` | 不启动子进程或外部服务 |
| `composer test:feature` | HTTP、CLI、fork 和外部服务集成 |
| `composer check:architecture` | 布局、初始化、资源、reset 与公开 API |
| `composer analyse` | PHPStan level 5，无 baseline |
| `composer style` | PHP_CodeSniffer，必须 0 error |
| `composer style:fix` | 自动修复支持的格式问题 |

Redis、MySQL、Memcached、Kafka 或其他可选服务不可达时必须如实报告，不得修改测试或断言掩盖环境失败。

## 编码规范

`phpcs.xml` 是可执行的规范依据。PlatoPHP 遵循 PSR-12，但明确采用以下约定：

| 范围 | PlatoPHP 约定 |
| --- | --- |
| 类名 | 蛇形小写 |
| 方法名 | 蛇形小写 |
| 私有成员 | 允许下划线前缀 |
| 大括号 | Allman 风格 |
| 控制结构 | 括号内侧留空格：`if ( $ready )` |

PSR-3 与 PSR-16 适配器保留接口规定的 camelCase 方法名。命名空间、目录、文件名和类名必须严格
对应。源码注释、docblock、测试描述、配置与工作流注释、TODO 和提交信息均使用英文。

## 公开 API 变更

架构门禁会把全部 public 与 protected 符号和 `tests/tools/api-snapshot.txt` 对比。确需修改时运行：

```bash
composer check:architecture -- --update-api
```

更新后的快照与实现一起提交，并在 `CHANGELOG.md` 中说明行为变化及原因。公开契约变化时同步更新中英文文档。

## 文档构建

安装开发依赖后，在 Docker PHP 容器内构建文档：

```bash
php docs/build.php
```

构建器会校验中英文页面一一对应、源文件、语言切换、静态资源和本地链接。不得手工修改生成的 HTML 或
manifest hash。

报告漏洞前请阅读 [`SECURITY.md`](https://github.com/lumnd/platophp/blob/main/SECURITY.md)，并使用其中说明的
私密渠道，不要公开提交安全问题。
