<?php

/**
 * Storage disk contract: what the storage facade needs from a backend
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\storage;

/**
 * The calls a storage backend has to answer.
 *
 * Paths are always **relative and forward slashed**, whatever the backend and whatever the host
 * operating system: `avatars/7/original.png`. A disk turns that into a filesystem path or an object
 * key of its own; a caller never sees either, which is the whole point of the interface.
 *
 * A leading slash, a `..` segment and a backslash are all rejected by the disk rather than
 * normalised away. Normalising is how a path traversal becomes a silent one -- `../../etc/passwd`
 * cleaned up to `etc/passwd` reads a file the caller did not ask for and nobody notices.
 *
 * Everything answers a plain value rather than throwing for the ordinary cases: a missing file
 * reads as null, a failed write as false. A misconfiguration -- no bucket, no credentials, an
 * unwritable root -- does throw, because that is not a case a caller can sensibly branch on.
 *
 * Unlike the queue drivers, the disks here really are interchangeable, with two documented
 * exceptions: `url()` needs a backend that has one, and `temporary_url()` needs one that can sign.
 * Both say so by answering null rather than by not existing.
 */
interface disk
{
    /**
     * Hand the disk its settings.
     *
     * Must not open anything: a process that never touches a disk must not connect to it.
     *
     * @param array<string, mixed> $config One entry of config/storage.php `disks`
     *
     * @return void
     */
    public function configure(array $config): void;

    /**
     * Read a file.
     *
     * @param string $path Relative path
     *
     * @return string|null  Null when there is no such file
     */
    public function get(string $path);

    /**
     * Write a file, creating whatever it needs to.
     *
     * @param string               $path     Relative path
     * @param string|resource      $contents Body, or a stream to read it from
     * @param array<string, mixed> $options  Backend specific: visibility, content type, metadata
     *
     * @return bool
     */
    public function put(string $path, $contents, array $options = []): bool;

    /**
     * Whether a file is there.
     *
     * @param string $path Relative path
     *
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * Delete a file. Deleting one that is not there is not an error.
     *
     * @param string $path Relative path
     *
     * @return bool
     */
    public function delete(string $path): bool;

    /**
     * Copy a file inside this disk.
     *
     * @param string $from Relative path
     * @param string $to   Relative path
     *
     * @return bool
     */
    public function copy(string $from, string $to): bool;

    /**
     * Move a file inside this disk.
     *
     * @param string $from Relative path
     * @param string $to   Relative path
     *
     * @return bool
     */
    public function move(string $from, string $to): bool;

    /**
     * Size of a file in bytes.
     *
     * @param string $path Relative path
     *
     * @return int|null  Null when there is no such file
     */
    public function size(string $path);

    /**
     * Unix time a file was last modified.
     *
     * @param string $path Relative path
     *
     * @return int|null  Null when there is no such file
     */
    public function modified(string $path);

    /**
     * Files directly under a prefix.
     *
     * @param string $prefix    Relative path of a directory, '' for the root
     * @param bool   $recursive Include everything below it as well
     *
     * @return array<int, string>  Relative paths
     */
    public function files(string $prefix = '', bool $recursive = false): array;

    /**
     * A url a browser can fetch the file from.
     *
     * @param string $path Relative path
     *
     * @return string|null  Null when this disk is not served over HTTP
     */
    public function url(string $path);

    /**
     * A url that grants access to the file for a while.
     *
     * @param string $path    Relative path
     * @param int    $seconds How long it stays valid
     *
     * @return string|null  Null when this disk cannot sign one
     */
    public function temporary_url(string $path, int $seconds = 3600);
}
