<?php

/**
 * Migration runner: applies pending files, rolls back the last batch, reports status
 *
 * State lives in one table, one row per file that has run, tagged with the batch it ran in.
 * Concurrency is handled by a named server side lock, so two deploys hitting the same database at
 * the same time do not both apply the same file. That lock is GET_LOCK, which makes this runner
 * MySQL only for now; another engine needs its own way to serialise.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\database;

use RuntimeException;

class migrator
{
    /**
     * Directory the migration files live in
     */
    protected string $_path;

    /**
     * Table the applied migrations are recorded in
     */
    protected string $_table;

    /**
     * Connection to run against, null for the default one
     */
    protected ?string $_connection;

    /**
     * @param string|null $connection
     */
    public function __construct(string $path, string $table = '#PB#_migrations', ?string $connection = null)
    {
        $real = realpath($path);
        if ( $real === false || !is_dir($real) || !is_readable($real) )
        {
            throw new RuntimeException("Migration directory is missing or unreadable: {$path}");
        }

        if ( !preg_match('/^(?:#PB#_)?[a-z][a-z0-9_]*$/i', $table) )
        {
            throw new RuntimeException("'{$table}' is not a usable migration table name");
        }

        $this->_path       = rtrim($real, DIRECTORY_SEPARATOR);
        $this->_table      = $table;
        $this->_connection = $connection;
    }

    /**
     * Apply every file that has not run yet, all under one batch number.
     *
     * @return array<int, string> Files applied, in the order they ran
     */
    public function migrate(): array
    {
        $this->_acquire_lock();

        try
        {
            $this->_ensure_repository();

            $ran     = array_column($this->_repository(), 'batch', 'migration');
            $pending = array_values(array_diff($this->_files(), array_keys($ran)));
            if ( !$pending )
            {
                return [];
            }

            // Load them all before running any: a file that does not parse should stop the run
            // before half the batch has been applied
            $migrations = [];
            foreach ( $pending as $filename )
            {
                $migrations[$filename] = $this->_load($filename);
            }

            $batch   = $this->_latest_batch() + 1;
            $applied = [];
            foreach ( $migrations as $filename => $migration )
            {
                $migration->up();

                $this->_record($filename, $batch);
                $applied[] = $filename;
            }

            return $applied;
        }
        finally
        {
            $this->_release_lock();
        }
    }

    /**
     * Undo the most recent batch, newest file first.
     *
     * @return array<int, string> Files rolled back
     */
    public function rollback(): array
    {
        $this->_acquire_lock();

        try
        {
            $this->_ensure_repository();

            $batch = $this->_latest_batch();
            if ( $batch < 1 )
            {
                return [];
            }

            $migrations = [];
            foreach ( $this->_batch($batch) as $filename )
            {
                $migrations[$filename] = $this->_load($filename);
            }

            $reverted = [];
            foreach ( $migrations as $filename => $migration )
            {
                $migration->down();

                $this->_forget($filename);
                $reverted[] = $filename;
            }

            return $reverted;
        }
        finally
        {
            $this->_release_lock();
        }
    }

    /**
     * Every file, and whether it has run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function status(): array
    {
        $this->_ensure_repository();

        $ran    = array_column($this->_repository(), 'batch', 'migration');
        $status = [];
        foreach ( $this->_files() as $filename )
        {
            $status[] = [
                'migration' => $filename,
                'status'    => isset($ran[$filename]) ? 'ran' : 'pending',
                'batch'     => isset($ran[$filename]) ? (int) $ran[$filename] : null,
            ];
        }

        return $status;
    }

    protected function _ensure_repository(): void
    {
        $this->_db()->statement(
            "CREATE TABLE IF NOT EXISTS {$this->_table} ("
            . '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'
            . '`migration` varchar(255) NOT NULL,'
            . '`batch` int unsigned NOT NULL,'
            . '`executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uniq_migration` (`migration`),'
            . 'KEY `idx_batch` (`batch`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    protected function _acquire_lock(): void
    {
        $locked = $this->_db()
            ->select_raw('SELECT GET_LOCK(?, 10) AS locked', [$this->_lock_name()], true);

        if ( (int) ($locked[0]['locked'] ?? 0) !== 1 )
        {
            throw new RuntimeException('Another migration run holds the lock');
        }
    }

    protected function _release_lock(): void
    {
        $this->_db()->select_raw('SELECT RELEASE_LOCK(?) AS released', [$this->_lock_name()], true);
    }

    protected function _lock_name(): string
    {
        return 'platophp_migration_' . md5($this->_table);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function _repository(): array
    {
        return $this->_db()->select_raw(
            "SELECT `migration`, `batch` FROM {$this->_table} ORDER BY `id` ASC",
            [],
            true
        );
    }

    protected function _record(string $filename, int $batch): void
    {
        $this->_db()->statement(
            "INSERT INTO {$this->_table} (`migration`, `batch`) VALUES (?, ?)",
            [$filename, $batch]
        );
    }

    protected function _latest_batch(): int
    {
        $rows = $this->_db()->select_raw(
            "SELECT COALESCE(MAX(`batch`), 0) AS batch FROM {$this->_table}",
            [],
            true
        );

        return (int) ($rows[0]['batch'] ?? 0);
    }

    /**
     * One batch, newest first, so rolling back undoes the files in reverse.
     *
     * @return array<int, string>
     */
    protected function _batch(int $batch): array
    {
        $rows = $this->_db()->select_raw(
            "SELECT `migration` FROM {$this->_table} WHERE `batch` = ? ORDER BY `id` DESC",
            [$batch],
            true
        );

        return array_column($rows, 'migration');
    }

    protected function _forget(string $filename): void
    {
        $this->_db()->statement("DELETE FROM {$this->_table} WHERE `migration` = ?", [$filename]);
    }

    protected function _db(): connection
    {
        return connection::instance($this->_connection);
    }

    /**
     * @return array<int, string> File names, sorted, which is also the order they apply in
     */
    protected function _files(): array
    {
        $files = [];
        foreach ( glob($this->_path . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file )
        {
            $filename = basename($file);
            if ( !preg_match('/^\d{8}_\d{6}_[a-z][a-z0-9_]*\.php$/', $filename) )
            {
                throw new RuntimeException("'{$filename}' does not look like a migration file name");
            }

            $files[] = $filename;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    protected function _load(string $filename): migration
    {
        $file = $this->_path . DIRECTORY_SEPARATOR . $filename;
        if ( !is_file($file) || !is_readable($file) )
        {
            throw new RuntimeException("Migration file is missing or unreadable: {$filename}");
        }

        $migration = require $file;
        if ( !$migration instanceof migration )
        {
            throw new RuntimeException(
                "{$filename} must return a " . migration::class . ' object'
            );
        }

        return $migration;
    }
}
