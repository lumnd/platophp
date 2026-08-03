<?php

/**
 * File cache driver: key-value store backed by a single hashed file
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

use plato\runtime;

/**
 * Key-value store kept in one file.
 *
 * Layout: a header holding the PHP exit stub, then a bucket table of int32 offsets, then the
 * nodes. Every bucket points at the head of a linked list of nodes that hashed to it, and a node
 * is a fixed size header followed by the key and the serialized value:
 *
 *     key_len (S, 2)  data_len (l, 4)  pre (l, 4)  next (l, 4)  time (l, 4)  exptime (l, 4)
 *
 * Overwriting a value only rewrites the node in place when the new value is not longer than the
 * old one; otherwise the new node is appended and the old one is left behind as a hole. Deleting
 * only marks the node. The file therefore grows with every update, which makes this a driver for
 * short lived caches -- use redis for anything that is written often.
 *
 * **Writers are serialised, readers are not.** set(), del() and inc() hold LOCK_EX across the
 * read of the chain and the write that follows, so two processes cannot both decide where a node
 * belongs and then overwrite each other's answer. get() takes no lock at all: it can observe a
 * node another process is in the middle of writing. That is the trade this driver makes for the
 * sake of read speed, and the reason it suits short lived caches rather than write heavy ones.
 *
 * **The handle is per process.** A descriptor inherited through fork() shares one open file
 * description with the parent, which shares the file offset and makes flock() useless between
 * them -- every LOCK_EX among processes holding one description is granted at once. open() checks
 * the runtime epoch and reopens rather than reusing what it inherited.
 *
 * @author itprato<2500875@qq>
 */
class file implements store
{
    /**
     * Written at the head of the cache file so a web server cannot serve its contents
     */
    private const EXIT_CODE = '<?php exit(); ?>';

    /**
     * Byte length of EXIT_CODE, i.e. the offset of the bucket table
     */
    private const EXIT_CODE_LENGTH = 16;

    /**
     * Byte length of a node header, see the class docblock for its fields
     */
    private const META_LENGTH = 22;

    /**
     * Cache file, including the .php suffix
     *
     * @var string
     */
    private $_cache_file = '';

    /**
     * Bucket count of the hash table; the file starts out this many int32 entries long,
     * and holds roughly _mask_value * _link_max / 2 values
     *
     * @var int
     */
    private $_mask_value = 0x5FFFF;

    /**
     * Longest chain walked before giving up; a longer one means the hash degenerated
     *
     * @var int
     */
    private $_link_max = 10000;

    /**
     * File size in bytes above which the file is rebuilt, default 1G
     *
     * @var int
     */
    private $_file_max = 1073741824;

    /**
     * @var resource|null
     */
    private $_cache_fp = null;

    /**
     * Runtime epoch the handle was opened in, null while there is none
     *
     * @var int|null
     */
    private $_epoch = null;

    /**
     * How many times LOCK_EX has been taken and not yet released.
     *
     * inc() locks around a get() and a set() that lock again, and flock() gives no nesting of its
     * own: the inner LOCK_UN would open the window the outer lock was taken to close.
     *
     * @var int
     */
    private $_lock_depth = 0;

    /**
     * Single process mode: writes are not locked. Only set it when nothing else, including
     * another php-fpm worker, has the same file open.
     *
     * @var bool
     */
    public $is_single = false;

    /**
     * @param string $cache_file Path of the cache file, without the .php suffix
     * @param bool   $is_single  See $is_single
     */
    public function __construct($cache_file = 'filecache_data', $is_single = false)
    {
        $this->_cache_file = $cache_file . '.php';
        $this->is_single   = (bool) $is_single;

        if ( !file_exists($this->_cache_file) )
        {
            $this->_create();
        }
        elseif ( filesize($this->_cache_file) > $this->_file_max )
        {
            $this->rebuild();
        }
    }

    public function __destruct()
    {
        if ( $this->_cache_fp )
        {
            @fclose($this->_cache_fp);
            $this->_cache_fp = null;
        }
    }

    /**
     * Open a store and its file.
     *
     * @param string $cachefile Path of the cache file, without the .php suffix
     *
     * @return self
     */
    public static function factory($cachefile = '')
    {
        $store = new self($cachefile);
        $store->open();

        return $store;
    }

    /**
     * Open the cache file, creating it when it is missing.
     *
     * @return resource
     *
     * @throws \Exception When the file exists but cannot be opened
     */
    public function open()
    {
        $epoch = runtime::epoch();

        if ( $this->_cache_fp && $this->_epoch === $epoch )
        {
            return $this->_cache_fp;
        }

        // Inherited through a fork. Dropping the reference closes this process' descriptor and
        // leaves the parent's alone; what must not happen is going on using it
        $this->_cache_fp   = null;
        $this->_lock_depth = 0;
        $this->_epoch      = $epoch;

        if ( !file_exists($this->_cache_file) )
        {
            return $this->_create();
        }

        $this->_cache_fp = @fopen($this->_cache_file, 'rb+');
        if ( !$this->_cache_fp )
        {
            throw new \Exception('Cache file is not exists or no purview!');
        }

        return $this->_cache_fp;
    }

    /**
     * Close the file. Writes unlock the file themselves, so this is rarely needed.
     *
     * @return bool
     */
    public function close(): bool
    {
        if ( $this->_cache_fp )
        {
            @fclose($this->_cache_fp);
        }
        $this->_cache_fp   = null;
        $this->_lock_depth = 0;
        $this->_epoch      = null;

        return true;
    }

    /**
     * Drop every value by resetting the file.
     *
     * Unlinks first, because _create() no longer truncates an existing file -- it has to leave a
     * table another process just wrote alone, which is the opposite of what this call wants.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->close();
        @unlink($this->_cache_file);
        $this->_create();

        return true;
    }

    /**
     * Reclaim the space held by overwritten and deleted nodes, which means starting over: the
     * file is a cache, rebuilding it drops what it holds.
     *
     * @param bool $isforce Rebuild outside the 2:00 - 6:00 window as well
     *
     * @return resource|false  False when the call was skipped
     */
    public function rebuild($isforce = false)
    {
        // Off peak hours only, a rebuild reads and writes the whole file
        if ( !$isforce && (date('G') < 2 || date('G') > 6) )
        {
            return false;
        }

        $this->close();
        @unlink($this->_cache_file);

        return $this->_create();
    }

    /**
     * Read a value.
     *
     * The node is looked up and its value read in one pass: _node() leaves the file pointer right
     * after the stored key, which is where the serialized value begins.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing, deleted or expired
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        $node = $this->_node($key);

        if ( !is_array($node) || empty($node['curkey']) )
        {
            return $default;
        }

        // data_len 0 is the tombstone del() writes; serialize() never produces fewer than two bytes
        if ( $node['data_len'] <= 0 || $this->_expired($node) )
        {
            return $default;
        }

        return unserialize((string) fread($this->_cache_fp, $node['data_len']));
    }

    /**
     * Whether the store holds a value under a key.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key): bool
    {
        return $this->_live_node($key) !== null;
    }

    /**
     * Write a value.
     *
     * @param string $key     Key
     * @param mixed  $value   Value; anything serialize() accepts, null and false included
     * @param int    $expire  Lifetime in seconds, 0 to keep the value forever
     *
     * @return bool
     */
    public function set($key, $value, $expire = 0): bool
    {
        if ( !$key )
        {
            return false;
        }

        $this->open();

        list($key_index, $key_sign) = $this->_get_index_sign($key);

        $value = serialize($value);

        // The lock covers the lookup too, not just the writes. Reading the chain outside it let
        // two processes decide independently where the node belongs, and whichever wrote second
        // relinked the bucket around the other one's node
        $this->_lock(LOCK_EX);

        try
        {
            $head_pos = $this->_read_bucket($key_index);
            $cur_node = $head_pos == 0 ? false : $this->_node($key);

            // Empty bucket, or a chain this key could not be looked up in: start a new chain
            if ( !is_array($cur_node) )
            {
                $node_pos = $this->_append_node($key_sign, $value, 0, 0, $expire);
                $this->_write_bucket($key_index, $node_pos);
            }
            // Key is not in the chain yet: append the node and link it after the last one
            elseif ( empty($cur_node['curkey']) )
            {
                $node_pos = $this->_append_node($key_sign, $value, $cur_node['pos'], 0, $expire);
                $this->_write_int($cur_node['pos'] + 10, $node_pos);
            }
            // The new value fits in the old node: overwrite it where it is
            elseif ( strlen($value) <= $cur_node['data_len'] )
            {
                $this->_write_node($cur_node['pos'], $key_sign, $value, $cur_node['pre'], $cur_node['next'], $expire);
            }
            // The new value is longer: append a new node and unlink the old one
            else
            {
                $node_pos = $this->_append_node($key_sign, $value, $cur_node['pre'], $cur_node['next'], $expire);

                if ( $cur_node['pre'] > 0 )
                {
                    $this->_write_int($cur_node['pre'] + 10, $node_pos);
                }
                else
                {
                    $this->_write_bucket($key_index, $node_pos);
                }

                if ( $cur_node['next'] > 0 )
                {
                    $this->_write_int($cur_node['next'] + 6, $node_pos);
                }
            }
        }
        finally
        {
            $this->_lock(LOCK_UN);
        }

        return true;
    }

    /**
     * Mark a value as deleted. The node itself stays where it is until the next rebuild.
     *
     * @param string $key Key
     *
     * @return int  1 when a value was deleted, 0 when there was nothing to delete
     */
    public function del($key): int
    {
        $this->open();
        if ( $key == '' )
        {
            return 0;
        }

        // Locked around the lookup as well, so the node found is still the node marked
        $this->_lock(LOCK_EX);

        try
        {
            $cur_node = $this->_node($key);
            if ( !is_array($cur_node) || empty($cur_node['curkey']) || $cur_node['data_len'] <= 0 )
            {
                return 0;
            }

            // A zero data_len is what get() reads as "deleted"
            $this->_write_int($cur_node['pos'] + 2, 0);
        }
        finally
        {
            $this->_lock(LOCK_UN);
        }

        return 1;
    }

    /**
     * Add to a counter, creating it at zero when it does not exist yet.
     *
     * The file has no counter command, so the value is read and written back -- under LOCK_EX,
     * which _lock() nests so that the set() inside does not release it early. That makes the
     * counter exact between processes sharing this file, but the file is still the wrong place
     * for a counter written often: every step that does not fit the old node appends a new one.
     *
     * @param string $key  Key
     * @param int    $step Step, negative to subtract
     *
     * @return int|false  The new value
     */
    public function inc($key, $step = 1)
    {
        $this->open();

        $this->_lock(LOCK_EX);

        try
        {
            $value = (int) $this->get($key, 0) + (int) $step;

            return $this->set($key, $value) ? $value : false;
        }
        finally
        {
            $this->_lock(LOCK_UN);
        }
    }

    /**
     * Set or replace the lifetime of a key that already exists.
     *
     * The node format carries the lifetime next to the value, and there is no way to rewrite one
     * without the other, so this reads the value back and writes it again. LOCK_EX covers both, so
     * a concurrent set() cannot have its value overwritten by the older one this read.
     *
     * @param string $key    Key
     * @param int    $expire Lifetime in seconds from now, 0 or less to make the key permanent
     *
     * @return bool  False when the key does not exist
     */
    public function expire($key, $expire): bool
    {
        $this->open();
        $this->_lock(LOCK_EX);

        try
        {
            if ( $this->_live_node($key) === null )
            {
                return false;
            }

            $miss  = new \stdClass();
            $value = $this->get($key, $miss);

            if ( $value === $miss )
            {
                return false;
            }

            return $this->set($key, $value, (int) max(0, $expire));
        }
        finally
        {
            $this->_lock(LOCK_UN);
        }
    }

    /**
     * Remaining lifetime of a key, following the redis convention.
     *
     * @param string $key Key
     *
     * @return int  Seconds left, -1 when the key never expires, -2 when it does not exist
     */
    public function ttl($key): int
    {
        $cur_node = $this->_live_node($key);
        if ( $cur_node === null )
        {
            return -2;
        }

        if ( $cur_node['exptime'] <= 0 )
        {
            return -1;
        }

        $left = $cur_node['time'] + $cur_node['exptime'] - time();

        return $left > 0 ? $left : -2;
    }

    /**
     * Every node of the chain a key hashes to, for debugging the store itself.
     *
     * @param string $key Key
     *
     * @return array<int, array<string, mixed>>|false
     */
    public function get_list($key)
    {
        $this->open();
        if ( $key == '' )
        {
            return false;
        }

        list($key_index) = $this->_get_index_sign($key);

        $n_pos = $this->_read_bucket($key_index);
        if ( $n_pos == 0 )
        {
            return false;
        }

        $n = 0;
        $link_datas = [];
        do
        {
            $cur_node = $this->_read_node($n_pos);
            if ( $cur_node === false )
            {
                break;
            }

            $cur_node['key']  = fread($this->_cache_fp, $cur_node['key_len']);
            $cur_node['data'] = $cur_node['data_len'] > 0
                ? unserialize(fread($this->_cache_fp, $cur_node['data_len']))
                : '**mark delete status**';

            $link_datas[] = $cur_node;
            $n_pos = $cur_node['next'];
        }
        while ( $n_pos > 0 && ++$n < $this->_link_max );

        return $link_datas;
    }

    /**
     * Create the cache file: the exit stub followed by an empty bucket table.
     *
     * Opened with 'cb+' rather than 'wb+', and the table is written only once the lock is held and
     * the file is confirmed to be short. 'wb+' truncates at open, before any lock can be taken, so
     * a cold start with several php-fpm workers had each of them truncate what the others had just
     * written and interleave their tables into an unreadable file.
     *
     * @return resource
     *
     * @throws \Exception When the file cannot be created
     */
    private function _create()
    {
        $dir = dirname($this->_cache_file);
        if ( !is_dir($dir) )
        {
            @mkdir($dir, 0775, true);
        }

        $this->_cache_fp = @fopen($this->_cache_file, 'cb+');
        if ( !$this->_cache_fp )
        {
            throw new \Exception('Failed to open stream: Permission denied');
        }

        $this->_epoch = runtime::epoch();

        @chmod($this->_cache_file, 0664);
        flock($this->_cache_fp, LOCK_EX);

        $buckets  = $this->_mask_value + 1;
        $expected = self::EXIT_CODE_LENGTH + $buckets * 4;
        $stat     = fstat($this->_cache_fp);

        // Somebody else got here first and wrote the whole table while this call waited for the
        // lock; adopting it is the point of the check
        if ( $stat === false || (int) $stat['size'] < $expected )
        {
            ftruncate($this->_cache_fp, 0);
            rewind($this->_cache_fp);
            fwrite($this->_cache_fp, self::EXIT_CODE);

            // One int32 per bucket, written in blocks: the table is 1.5M by default
            $block = str_repeat(pack('l', 0), 1024);
            for ( $i = intdiv($buckets, 1024); $i > 0; $i-- )
            {
                fwrite($this->_cache_fp, $block);
            }
            if ( $rest = $buckets % 1024 )
            {
                fwrite($this->_cache_fp, str_repeat(pack('l', 0), $rest));
            }
        }

        rewind($this->_cache_fp);
        flock($this->_cache_fp, LOCK_UN);

        return $this->_cache_fp;
    }

    /**
     * Bucket index and stored key of a key.
     *
     * @param string $key Key
     *
     * @return array{0: int, 1: string}
     */
    private function _get_index_sign($key)
    {
        // Keys are stored inline, so long ones are hashed down to a fixed length
        if ( strlen($key) > 32 )
        {
            $key = md5($key);
        }

        return [$this->_get_index($key), $key];
    }

    /**
     * Bucket index of a stored key.
     *
     * @param string $key Key
     *
     * @return int
     */
    private function _get_index($key)
    {
        $l = strlen($key);
        $h = 0x238f13af;
        while ( $l-- )
        {
            $h += ($h << 5);
            $h ^= ord($key[$l]);
            $h &= 0x7fffffff;
        }

        return $h % $this->_mask_value;
    }

    /**
     * Offset of the first node of a bucket, 0 when the bucket is empty.
     *
     * @param int $key_index Bucket index
     *
     * @return int
     */
    private function _read_bucket($key_index)
    {
        fseek($this->_cache_fp, $key_index * 4 + self::EXIT_CODE_LENGTH);
        $darr = unpack('l1h', (string) fread($this->_cache_fp, 4));

        return $darr === false ? 0 : (int) $darr['h'];
    }

    /**
     * Point a bucket at a node.
     *
     * @param int $key_index Bucket index
     * @param int $node_pos  Node offset
     *
     * @return void
     */
    private function _write_bucket($key_index, $node_pos)
    {
        $this->_write_int($key_index * 4 + self::EXIT_CODE_LENGTH, $node_pos);
    }

    /**
     * Read a node header. Leaves the file pointer right after it, on the stored key.
     *
     * @param int $pos Node offset
     *
     * @return array<string, mixed>|false  False when the file is truncated at $pos
     */
    private function _read_node($pos)
    {
        fseek($this->_cache_fp, $pos);
        $info_dat = (string) fread($this->_cache_fp, self::META_LENGTH);
        if ( strlen($info_dat) != self::META_LENGTH )
        {
            return false;
        }

        $node = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $info_dat);
        if ( $node === false )
        {
            return false;
        }

        $node['pos']    = $pos;
        $node['curkey'] = false;

        return $node;
    }

    /**
     * Append a node at the end of the file.
     *
     * @param string $key_sign Stored key
     * @param string $value    Serialized value
     * @param int    $pre      Offset of the previous node of the chain
     * @param int    $next     Offset of the next node of the chain
     * @param int    $exptime  Lifetime in seconds
     *
     * @return int  Offset the node was written at
     */
    private function _append_node($key_sign, $value, $pre, $next, $exptime)
    {
        fseek($this->_cache_fp, 0, SEEK_END);
        $pos = (int) ftell($this->_cache_fp);
        fwrite($this->_cache_fp, $this->_pack_node($key_sign, $value, $pre, $next, $exptime));

        return $pos;
    }

    /**
     * Overwrite the node at $pos.
     *
     * @param int    $pos      Node offset
     * @param string $key_sign Stored key
     * @param string $value    Serialized value
     * @param int    $pre      Offset of the previous node of the chain
     * @param int    $next     Offset of the next node of the chain
     * @param int    $exptime  Lifetime in seconds
     *
     * @return void
     */
    private function _write_node($pos, $key_sign, $value, $pre, $next, $exptime)
    {
        fseek($this->_cache_fp, $pos);
        fwrite($this->_cache_fp, $this->_pack_node($key_sign, $value, $pre, $next, $exptime));
    }

    /**
     * @param string $key_sign Stored key
     * @param string $value    Serialized value
     * @param int    $pre      Offset of the previous node of the chain
     * @param int    $next     Offset of the next node of the chain
     * @param int    $exptime  Lifetime in seconds
     *
     * @return string
     */
    private function _pack_node($key_sign, $value, $pre, $next, $exptime)
    {
        return pack(
            'Slllll',
            strlen($key_sign),
            strlen($value),
            $pre,
            $next,
            time(),
            $exptime
        ) . $key_sign . $value;
    }

    /**
     * Write one int32 at an absolute offset.
     *
     * @param int $pos   Offset
     * @param int $value Value
     *
     * @return void
     */
    private function _write_int($pos, $value)
    {
        fseek($this->_cache_fp, $pos);
        fwrite($this->_cache_fp, pack('l', $value));
    }

    /**
     * Lock the file around a write, unless the store runs in single process mode.
     *
     * Nested: flock() has no notion of depth, so an inner LOCK_UN would release a lock an outer
     * caller is still relying on. Only the outermost pair reaches the file.
     *
     * @param int $operation LOCK_EX or LOCK_UN
     *
     * @return void
     */
    private function _lock($operation)
    {
        if ( $this->is_single )
        {
            return;
        }

        if ( $operation === LOCK_UN )
        {
            if ( $this->_lock_depth > 0 && --$this->_lock_depth === 0 )
            {
                flock($this->_cache_fp, LOCK_UN);
            }

            return;
        }

        if ( $this->_lock_depth++ === 0 )
        {
            flock($this->_cache_fp, $operation);
        }
    }

    /**
     * Walk the chain a key hashes to and stop on its node.
     *
     * The file pointer is left right after the stored key, i.e. on the serialized value, so a
     * caller that wants the value reads it without a second traversal.
     *
     * @param string $key Key
     *
     * @return array<string, mixed>|false  The node with `curkey` true when the key is in the chain;
     *                                     the last node walked, `curkey` false, when it is not;
     *                                     false when there is no chain to walk at all
     */
    private function _node($key)
    {
        $this->open();

        if ( !$key )
        {
            return false;
        }

        list($key_index, $key_sign) = $this->_get_index_sign($key);

        $n_pos = $this->_read_bucket($key_index);
        if ( $n_pos == 0 )
        {
            return false;
        }

        $n        = 0;
        $cur_node = false;

        do
        {
            $cur_node = $this->_read_node($n_pos);
            if ( $cur_node === false )
            {
                return false;
            }

            if ( $cur_node['key_len'] == 0 )
            {
                $n_pos = $cur_node['next'];
                continue;
            }

            if ( fread($this->_cache_fp, $cur_node['key_len']) === $key_sign )
            {
                $cur_node['curkey'] = true;

                return $cur_node;
            }

            $n_pos = $cur_node['next'];
        }
        while ( $n_pos > 0 && ++$n < $this->_link_max );

        return $cur_node;
    }

    /**
     * The node of a key that is present, not deleted and not expired.
     *
     * @param string $key Key
     *
     * @return array<string, mixed>|null
     */
    private function _live_node($key)
    {
        $node = $this->_node($key);

        if ( !is_array($node) || empty($node['curkey']) || $node['data_len'] <= 0 )
        {
            return null;
        }

        return $this->_expired($node) ? null : $node;
    }

    /**
     * Whether a node's lifetime has run out.
     *
     * @param array<string, mixed> $node Node header
     *
     * @return bool
     */
    private function _expired($node): bool
    {
        return $node['exptime'] > 0 && $node['time'] + $node['exptime'] < time();
    }
}
