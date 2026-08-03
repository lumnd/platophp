# Console and Scheduling

## Built-in commands

```text
migrate / migrate:status / migrate:rollback
make:migration / make:seeder / make:controller / make:middleware / make:command
db:seed
queue:work / queue:status / queue:retry
schedule:run / schedule:list / schedule:exec
```

Run `php vendor/bin/plato --help` for current commands and options. Paths resolve from a command-line option, a `PLATO_*` environment variable, project-root `plato.config.php`, then a convention.

Application commands implement `plato\console\command` and are registered under `console.commands` in `config/config.php` or in `plato.config.php`. Generators write class files only; they do not mutate Composer mappings and never overwrite an existing file.

## Scheduling

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

Invoke the scheduler once per minute from system cron:

```cron
* * * * * cd /srv/app && php vendor/bin/plato schedule:run
```

Scheduling supports standard five-field cron expressions plus aliases such as `@hourly` and `@daily`. `overlap => false` uses a process-held file lock to prevent overlap. The framework only decides which tasks are due and runs them; startup, crash recovery, and daemonization belong to the system process manager.

`schedule::inspect()` returns every normalized configuration entry with `name`, `expression`,
`command`, `call`, `overlap`, and `error`. It neither writes console output nor runs callbacks;
invalid entries remain visible with an error. `schedule::tasks()` is the executable view: it reports
and drops invalid entries. This separation lets an application health check inspect a broken
schedule without making the page itself fail or write to stderr.

## Lifecycle callbacks

Applications can observe execution and gate automatic runs without replacing the scheduler:

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

`should_run` is asked after a task is due and only by `schedule:run`; returning false skips it.
`--force` ignores the expression, not this gate. `schedule:exec --task=NAME` deliberately bypasses
it, so an operator can run a paused task explicitly. `before` and `after` wrap work that actually
runs. The after result contains `ok`, `started_at`, `finished_at`, `duration` in seconds,
`exit_code`, and `error`. `skipped` receives the stable reason `filtered` or `overlap`.

### Which process runs a callback

`should_run` always runs in the `schedule:run` process. `before`, `after`, and the `overlap` skip
run wherever the work does, which is decided by the kind of task:

| Task | `should_run` | `before` / `after` / `overlap` skip |
| --- | --- | --- |
| `call` | `schedule:run` | `schedule:run`, in process |
| `command` | `schedule:run` | the spawned `schedule:exec` child |

A command task is a subprocess by design, and the child is the process holding that task's lock and
watching it finish, so it is the one that reports. Write callbacks against somewhere both processes
can read -- a table, a cache key, a log -- rather than a static this process would keep, and do not
expect the parent to observe a command task at all. For the same reason a configuration handed in
with `schedule::configure()` reaches callable tasks only: a child reads `config/schedule.php`.

Lifecycle callbacks are observers. A failing `before`, `after`, or `skipped` callback is logged and
does not change the task result. A failing `should_run` callback fails closed for that task, reports
the observer failure, and emits `skipped(..., 'filtered')`.
