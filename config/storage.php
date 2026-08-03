<?php

/**
 * File storage.
 *
 * One relative path space and one set of calls, whatever is behind them:
 *
 *     storage::put('avatars/7.png', $bytes);          // the default disk
 *     storage::disk('archive')->put('backups/db.sql', $h);
 *
 * Paths are relative and forward slashed. An absolute path, a `..` segment, a backslash or a
 * protocol wrapper is **refused** rather than cleaned up -- see plato\storage\path for why.
 *
 * The package ships only the local disk. Applications can register remote storage adapters through
 * storage::extend() without making the framework depend on a cloud vendor or SDK.
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

return [
    // Which disk storage::put() and its siblings use
    'default' => $_ENV['STORAGE_DISK'] ?? 'local',

    'disks' => [
        'local' => [
            'driver' => 'local',
            // Left null so it resolves to data_path('storage') on first use; the framework cannot
            // know an application's layout at the time this file is read
            'root' => $_ENV['STORAGE_ROOT'] ?? null,
            // Public base url of the root, for storage::url(). Null when it is not served over HTTP
            'url' => $_ENV['STORAGE_URL'] ?? null,
            // private => 0600 files and 0700 directories, public => 0644 and 0755
            'visibility' => 'private',
        ],
    ],
];
