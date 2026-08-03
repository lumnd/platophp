<?php

/**
 * Scheduled tasks.
 *
 * One crontab entry drives the whole table:
 *
 *     * * * * * cd /srv/app && php vendor/bin/plato schedule:run >> /dev/null 2>&1
 *
 * `php vendor/bin/plato schedule:list` prints what is here with the next due time of each entry,
 * which is the quickest way to check an expression before trusting it to a machine.
 *
 * A task is an array with:
 *
 *   expression  Five field cron, or one of @yearly @monthly @weekly @daily @hourly @always.
 *               Ranges, lists and steps all work, and so do three letter month and day names:
 *               '30 3 * * mon-fri'. Defaults to @always, every minute.
 *   command     A console command line, run as its own process: 'queue:work --once'. The command
 *               has to be one `plato` knows -- a built in, or one the application registered.
 *   call        Any callable, run inside schedule:run itself. Use instead of command when a
 *               process start would cost more than the work.
 *   name        What the output and the overlap lock call it; defaults to the command line.
 *   overlap     false to keep a run that is still going from being joined by the next one.
 *               Guarded by a file lock under data_path('schedule'), released by the process
 *               exiting however it exits. Defaults to true, meaning overlapping is allowed.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

return [
    // Nothing runs while this is false, whatever the table below holds
    'enable' => true,

    // Empty on purpose: the framework schedules nothing of its own. Configuration is merged into
    // this, not replacing it, so a default entry here could not be removed by an application.
    'tasks' => [
        // ['expression' => '*/5 * * * *', 'command' => 'queue:work --once', 'overlap' => false],
        // ['expression' => '@daily', 'call' => ['model\report', 'nightly']],
    ],

    // Optional application lifecycle callbacks. should_run is only asked by schedule:run; a
    // manual schedule:exec remains available. Observer failures are logged and do not replace the
    // result of work that ran. See the scheduling guide for callback signatures.
    'should_run' => null,
    'before'     => null,
    'after'      => null,
    'skipped'    => null,
];
