<?php

/**
 * Storage disk: the local filesystem, under one root
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\storage;

use plato\exception\storage_exception;
use plato\plato;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Files under a configured root directory.
 *
 * Settings:
 *
 *     root        Directory everything lives under; required
 *     url         Public url the root is served at, for url(). Null when it is not served
 *     visibility  'private' (0600 / 0700) or 'public' (0644 / 0755), private by default
 *     permissions Explicit ['file' => 0644, 'dir' => 0755], overriding visibility
 *
 * The root is created on the first write and not on configure(): a process that only reads from a
 * disk it never uses should not leave a directory behind.
 *
 * **The root is a boundary, not a prefix.** Every path is validated by plato\storage\path before it
 * gets here, and the result is joined onto the root -- so there is no way to address anything
 * outside it, and no realpath() check to get subtly wrong. A symlink inside the root pointing out
 * of it is the one thing that escapes, and that is the operating system's answer to what a symlink
 * means rather than something this class can decide.
 */
class local implements disk
{
    /**
     * Settings
     *
     * @var array<string, mixed>
     */
    private $_config = [];

    /**
     * Absolute root, with no trailing separator
     *
     * @var string
     */
    private $_root = '';

    /**
     * @param array<string, mixed> $config
     *
     * @return void
     */
    public function configure(array $config): void
    {
        $this->_config = $config;

        $root = (string) ($config['root'] ?? '');

        // config/storage.php cannot name a directory: the application's layout is only known once
        // registry() has run, which is after the configuration file was written
        if ( $root === '' )
        {
            $root = plato::data_path('storage');
        }

        $this->_root = rtrim($root, '/\\');

        if ( $this->_root === '' )
        {
            throw new storage_exception('a local storage disk needs a root directory');
        }
    }

    /**
     * @param string $path
     *
     * @return string|null
     */
    public function get(string $path)
    {
        $file = $this->_absolute($path);

        if ( !is_file($file) )
        {
            return null;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    /**
     * @param string               $path
     * @param string|resource      $contents
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function put(string $path, $contents, array $options = []): bool
    {
        $file = $this->_absolute($path);

        if ( !$this->_make_directory(dirname($file)) )
        {
            return false;
        }

        if ( is_resource($contents) )
        {
            $target = @fopen($file, 'w');

            if ( !is_resource($target) )
            {
                return false;
            }

            // Streamed, so a large upload never has to exist in memory in one piece
            $written = stream_copy_to_stream($contents, $target);
            fclose($target);

            if ( $written === false )
            {
                return false;
            }
        }
        elseif ( @file_put_contents($file, (string) $contents, LOCK_EX) === false )
        {
            return false;
        }

        @chmod($file, $this->_permission('file', $options));

        return true;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function exists(string $path): bool
    {
        return is_file($this->_absolute($path));
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): bool
    {
        $file = $this->_absolute($path);

        // Deleting what is not there is the state the caller asked for
        return !is_file($file) || @unlink($file);
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    public function copy(string $from, string $to): bool
    {
        $source = $this->_absolute($from);
        $target = $this->_absolute($to);

        return is_file($source)
            && $this->_make_directory(dirname($target))
            && @copy($source, $target);
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    public function move(string $from, string $to): bool
    {
        $source = $this->_absolute($from);
        $target = $this->_absolute($to);

        return is_file($source)
            && $this->_make_directory(dirname($target))
            && @rename($source, $target);
    }

    /**
     * @param string $path
     *
     * @return int|null
     */
    public function size(string $path)
    {
        $file = $this->_absolute($path);

        if ( !is_file($file) )
        {
            return null;
        }

        $size = @filesize($file);

        return $size === false ? null : (int) $size;
    }

    /**
     * @param string $path
     *
     * @return int|null
     */
    public function modified(string $path)
    {
        $file = $this->_absolute($path);

        if ( !is_file($file) )
        {
            return null;
        }

        $at = @filemtime($file);

        return $at === false ? null : (int) $at;
    }

    /**
     * @param string $prefix
     * @param bool   $recursive
     *
     * @return array<int, string>
     */
    public function files(string $prefix = '', bool $recursive = false): array
    {
        $clean     = path::clean_prefix($prefix);
        $directory = $clean === '' ? $this->_root : $this->_root . DIRECTORY_SEPARATOR . $this->_native($clean);

        if ( !is_dir($directory) )
        {
            return [];
        }

        $found = [];

        if ( $recursive )
        {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        }
        else
        {
            $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        }

        foreach ( $items as $item )
        {
            if ( !$item->isFile() )
            {
                continue;
            }

            $found[] = $this->_relative($item->getPathname());
        }

        sort($found);

        return $found;
    }

    /**
     * @param string $path
     *
     * @return string|null
     */
    public function url(string $path)
    {
        $base = (string) ($this->_config['url'] ?? '');

        if ( $base === '' )
        {
            return null;
        }

        return rtrim($base, '/') . '/' . path::clean($path);
    }

    /**
     * A local disk has no signing key and no request to sign against.
     *
     * An application that wants expiring links for local files puts a controller in front of them
     * and checks the token itself: that is a routing decision, and a storage disk making one would
     * be guessing at how the application serves files.
     *
     * @param string $path
     * @param int    $seconds
     *
     * @return string|null  Always null
     */
    public function temporary_url(string $path, int $seconds = 3600)
    {
        return null;
    }

    /**
     * The absolute path of a relative one.
     *
     * @param  string $path
     * @return string
     * @throws storage_exception When the path is not one a disk may act on
     */
    private function _absolute(string $path): string
    {
        return $this->_root . DIRECTORY_SEPARATOR . $this->_native(path::clean($path));
    }

    /**
     * Forward slashes to whatever this platform separates directories with.
     *
     * @param  string $path
     * @return string
     */
    private function _native(string $path): string
    {
        return DIRECTORY_SEPARATOR === '/' ? $path : str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * An absolute path back to the relative form a caller uses.
     *
     * @param  string $file
     * @return string
     */
    private function _relative(string $file): string
    {
        $relative = substr($file, strlen($this->_root) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', (string) $relative);
    }

    /**
     * @param  string $directory
     * @return bool
     */
    private function _make_directory(string $directory): bool
    {
        if ( is_dir($directory) )
        {
            return true;
        }

        return @mkdir($directory, $this->_permission('dir', []), true) || is_dir($directory);
    }

    /**
     * The mode a file or a directory gets.
     *
     * @param  string               $kind    file|dir
     * @param  array<string, mixed> $options Per call overrides
     * @return int
     */
    private function _permission(string $kind, array $options): int
    {
        $explicit = (array) ($this->_config['permissions'] ?? []);

        if ( isset($explicit[$kind]) )
        {
            return (int) $explicit[$kind];
        }

        $visibility = (string) ($options['visibility'] ?? $this->_config['visibility'] ?? 'private');

        if ( $visibility === 'public' )
        {
            return $kind === 'dir' ? 0755 : 0644;
        }

        return $kind === 'dir' ? 0700 : 0600;
    }
}
