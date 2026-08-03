# 命令行与调度

## 内置命令

```text
migrate / migrate:status / migrate:rollback
make:migration / make:seeder / make:controller / make:middleware / make:command
db:seed
queue:work / queue:status / queue:retry
schedule:run / schedule:list / schedule:exec
```

运行 `php vendor/bin/plato --help` 查看当前命令和参数。路径解析顺序是命令行 option、`PLATO_*` 环境变量、项目根的 `plato.config.php`、默认约定。

应用命令实现 `plato\console\command`，并在 `config/config.php` 的 `console.commands` 或 `plato.config.php` 中注册。生成器只创建类文件，不修改宿主 Composer 映射，也不会覆盖已有文件。

## 调度

```php
// app/config/schedule.php
return [
    'enable' => true,
    'tasks' => [
        [
            'name' => 'retry-emails',
            'expression' => '*/5 * * * *',
            'command' => 'queue:retry --queue=emails',
            'overlap' => false,
        ],
        [
            'name' => 'cleanup',
            'expression' => '30 2 * * *',
            'call' => [app\jobs::class, 'cleanup'],
        ],
    ],
];
```

系统 cron 每分钟调用一次：

```cron
* * * * * cd /srv/app && php vendor/bin/plato schedule:run
```

调度支持标准五段 cron 表达式以及 `@hourly`、`@daily` 等别名。`overlap => false` 使用进程持有的文件锁防止重叠。框架只判断任务是否到期并执行；开机拉起、崩溃重启和守护化属于系统进程管理器。

`schedule::inspect()` 返回每条标准化配置，字段包括 `name`、`expression`、`command`、`call`、
`overlap` 与 `error`。它不写控制台，也不运行回调；无效条目会带着错误保留在结果中。
`schedule::tasks()` 是执行视图，会报告并剔除无效条目。应用健康检查因此可以查看损坏的计划配置，
而不会让页面本身失败或往 stderr 写内容。

## 生命周期回调

应用可以观察执行过程并控制自动运行，不需要接管调度器：

```php
return [
    'enable' => true,
    'tasks' => $tasks,
    'should_run' => static fn (array $task): bool => !task_state::paused($task['name']),
    'before' => static function (array $task): void {
        task_state::started($task['name']);
    },
    'after' => static function (array $task, array $result): void {
        task_state::finished($task['name'], $result);
    },
    'skipped' => static function (array $task, string $reason): void {
        task_state::skipped($task['name'], $reason);
    },
];
```

任务到期后，只有 `schedule:run` 会调用 `should_run`；返回 false 就跳过。`--force` 忽略的是表达式，
不是这个开关。人工执行 `schedule:exec --task=NAME` 刻意绕过它，因此暂停的任务仍可由操作员明确运行。
`before` 与 `after` 包住实际执行的任务；after 的结果包含 `ok`、`started_at`、`finished_at`、
以秒计的 `duration`、`exit_code` 和 `error`。`skipped` 收到稳定原因 `filtered` 或 `overlap`。

### 回调在哪个进程执行

`should_run` 始终在 `schedule:run` 进程里执行。`before`、`after` 和 `overlap` 跳过则跟着任务本身
走，由任务类型决定：

| 任务 | `should_run` | `before` / `after` / `overlap` 跳过 |
| --- | --- | --- |
| `call` | `schedule:run` | `schedule:run`，进程内 |
| `command` | `schedule:run` | 派生出的 `schedule:exec` 子进程 |

command 任务本来就设计成子进程执行，而持有该任务锁、等到它结束的正是这个子进程，所以由它上报。
回调要写到两个进程都能读的地方——数据库表、缓存键、日志——而不是写进本进程的静态属性，也不要指望
父进程能观察到 command 任务。同理，`schedule::configure()` 传入的配置只对 callable 任务生效：
子进程读的是 `config/schedule.php`。

生命周期回调属于观察链路。`before`、`after` 或 `skipped` 失败时会记日志，但不改变任务结果；
`should_run` 失败时只对该任务关闭运行，报告观察回调故障，并触发
`skipped(..., 'filtered')`。
