<?php

/**
 * Levelled logging with PSR-3 style context
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

use Throwable;

/**
 * Writes log entries to <log path>/<level>.log, or to a stream.
 *
 *     log::error('payment gateway timed out');
 *     log::info($order);                                 // object: property names and visibility only
 *     log::info('user {id} signed in', ['id' => 7]);     // -> "user 7 signed in"
 *     log::info('charge taken', ['order' => $id]);       // -> 'charge taken {"order":91}'
 *
 * **$context is PSR-3 context, not a prefix.** A `{key}` in the message is replaced by that key's
 * value and the key is consumed; whatever is left over is the entry's structured data, rendered as
 * json by `%context%` in log_output and as real fields under log_output = 'json'. Two conveniences
 * on top of the PSR-3 rule: a string $context is shorthand for `['tag' => $context]`, since that is
 * what the argument was used for before it meant anything else, and an `exception` key holding a
 * Throwable is rendered as `class: message at file:line` rather than json encoded. A value that
 * cannot become a string -- an array, a resource, an object with no __toString -- is not
 * substituted and stays in the context.
 *
 * **Shared context.** context() puts keys on every later entry, so one request's lines can be tied
 * together:
 *
 *     log::context(['rid' => 'a91f...', 'uid' => 7]);
 *
 * boot() seeds `rid` with a fresh request id, which is what makes one file per level survivable --
 * an error and the debug lines that led to it are in two files with the same rid. A per call key
 * beats a shared one of the same name. A resident worker moves the request id on through
 * plato::restamp(), and clears anything else it put there itself with forget_context().
 *
 * **Entries are buffered, not written on the spot.** write() holds them in memory and save()
 * puts them on disk; boot() registers save() as a shutdown function so a request never ends
 * with a half filled buffer. Two things flush early: reaching max_log entries at one level, and
 * running under CLI, where every entry is flushed as it arrives. That second one is why a worker
 * forked by plato\pool starts with an empty buffer instead of rewriting what its
 * parent had queued, and why a CLI entry pays for a write of its own.
 *
 * **Where it writes is log_type.** 'file' is one file per level under the log path. 'stderr' and
 * 'stdout' send every level to that stream instead, which is what a container wants: there is no
 * log directory to mount or rotate, the level is in the line already, and initialize() stops
 * insisting on a writable directory that nothing is going to use. A stream target also skips the
 * flock() and the inode check below, neither of which means anything on a pipe.
 *
 * **Each level file is opened once and the handle is held.** A write is a locked fwrite() rather
 * than an open, write, chmod and close, and the mode is set the one time the file gets created.
 * The handles live in plato\runtime, which drops them when the process forks: a worker that went
 * on writing through the description it inherited would share the file offset with its parent,
 * and flock() cannot serialise two processes holding one open file description -- every LOCK_EX
 * among them is granted at once. A handle is also dropped when the file behind the path has been
 * renamed or removed, so log rotation does not leave entries piling up in a file nobody reads.
 *
 * **The level is the file name.** $level is the buffer key and, lowercased, the file it lands in.
 * Passing a name that is not in self::$levels is supported and gives that name its own file:
 *
 *     log::write('payment', 'charge accepted');   // -> payment.log
 *
 * Such a level is kept out of the cli:: mirror, which only has methods for the built in names,
 * and _need_logging() weighs it as NOTICE since it has no severity of its own.
 *
 * **Threshold.** log_threshold is NONE, ALL, one level (that level and everything more severe),
 * or an explicit array of levels.
 *
 * plato::registry() calls boot() once the paths are known. A class used on its own -- a script
 * that pulls in nothing but this one -- boots on its first write instead, and logging turns
 * itself off when there is nowhere to write rather than throwing.
 */
class log
{
    /**
     * Log levels.
     *
     * TRACE and FATAL are the log4j aliases used by trace() and fatal(); they map onto DEBUG and
     * CRITICAL because cli:: only renders the levels listed in self::$levels.
     */
    public const NONE      = 0;    // logging disabled
    public const ALL       = 99;   // log everything
    public const TRACE     = 100;  // log4j alias of DEBUG
    public const DEBUG     = 100;  // detailed debug information
    public const INFO      = 200;  // relevant events, such as logins or SQL statements
    public const NOTICE    = 250;  // normal but significant events
    public const WARNING   = 300;  // exceptional occurrences that are not errors
    public const ERROR     = 400;  // runtime errors that do not require immediate action
    public const CRITICAL  = 500;  // critical conditions, such as an unavailable component
    public const FATAL     = 500;  // log4j alias of CRITICAL
    public const ALERT     = 550;  // action must be taken immediately
    public const EMERGENCY = 600;  // system is unusable

    /** @var array<int, string> Built in levels; anything outside this map is a caller defined one */
    public static $levels = [
        self::NONE      => 'NONE',
        self::ALL       => 'ALL',
        self::DEBUG     => 'DEBUG',
        self::INFO      => 'INFO',
        self::NOTICE    => 'NOTICE',
        self::WARNING   => 'WARNING',
        self::ERROR     => 'ERROR',
        self::CRITICAL  => 'CRITICAL',
        self::ALERT     => 'ALERT',
        self::EMERGENCY => 'EMERGENCY',
    ];

    /**
     * Entries waiting to be written, keyed by level.
     *
     * The message and its leftover context stay apart until save(): log_output = 'json' wants the
     * context as fields of the record, and flattening it into the message here would leave nothing
     * to build them from.
     *
     * @var array<int|string, array<int, array{msg: string, context: array<string, mixed>}>>
     */
    private static $_logs = [];

    /** @var int Entries buffered at one level before write() flushes without being asked */
    private static $_max_log = 128;

    /**
     * Context carried by every entry, see context()
     *
     * @var array<string, mixed>
     */
    private static $_shared = [];

    /**
     * Prefix of the runtime keys the append handles are registered under
     */
    private const SHARE_PREFIX = 'log.handle.';

    /**
     * log_type values that name a stream rather than the log directory
     *
     * @var array<string, string>
     */
    private const STREAMS = [
        'stderr' => 'php://stderr',
        'stdout' => 'php://stdout',
    ];

    /**
     * Whether boot() has already run
     *
     * @var bool
     */
    private static $_booted = false;

    /**
     * Check the log directory and arrange for the buffer to reach disk.
     *
     * plato::registry() calls this once the application paths are known, and write() calls it for
     * anyone who logs without booting the framework. Guarded because the shutdown function must
     * be registered exactly once; every extra handler would save the same buffer again.
     *
     * The fallback in write() is the one place in the framework where a boot() is called from
     * outside plato::_bootstrap(). It is deliberate: error_handler can reach write() while the bootstrap
     * is still failing, and a log line lost at exactly that moment is the one nobody can afford.
     * Nothing else may copy it -- every other class reads its settings through a lazy config().
     *
     * The shutdown function is the only thing that gets a partly filled buffer onto disk when a
     * request ends normally.
     *
     * @return void
     */
    public static function boot()
    {
        if ( self::$_booted )
        {
            return;
        }

        self::$_booted = true;

        static::initialize();

        // Every entry from here on carries the same request id, so the lines one request left in
        // several level files can be collected again
        self::context(['rid' => self::new_rid()]);

        register_shutdown_function(function ()
        {
            self::save();
        });
    }

    /**
     * Puts $data on every later entry, replacing the keys it names and leaving the rest.
     *
     * @param   array<string, mixed>  $data
     * @return  void
     */
    public static function context(array $data)
    {
        self::$_shared = array_merge(self::$_shared, $data);
    }

    /**
     * Drops shared context keys.
     *
     * A resident worker calls this between requests for anything request scoped it put there. The
     * request id is not dropped but replaced, by plato::restamp().
     *
     * @param   string[]|null  $keys  Keys to drop, or null for all of them
     * @return  void
     */
    public static function forget_context(?array $keys = null)
    {
        if ( $keys === null )
        {
            self::$_shared = [];
            return;
        }

        foreach ( $keys as $key )
        {
            unset(self::$_shared[$key]);
        }
    }

    /**
     * Drops the whole shared context, the request id with it.
     *
     * The request scoped half of this class. Keys put there with context() belong to the request
     * that put them there -- a user id, a tenant, an order number -- and a resident worker that
     * carried them over would label one client's lines with the identity of the client before it.
     * plato::reset_request() calls this, then plato::restamp() seeds a fresh `rid`; a caller doing
     * it by hand owes that second half, since an entry with no request id ties to nothing.
     *
     * Context that belongs to the process rather than to the request -- a worker index, a release
     * tag -- is put back by the registry `reset_handle`, which runs at the end of reset_request().
     *
     * @return  void
     */
    public static function reset()
    {
        self::$_shared = [];
    }

    /**
     * The context every entry currently carries.
     *
     * @return array<string, mixed>
     */
    public static function shared_context()
    {
        return self::$_shared;
    }

    /**
     * A fresh request id.
     *
     * Sixteen hex characters: long enough not to collide inside a log file worth reading, short
     * enough to sit at the front of every line. Falls back to uniqid() on a system with no usable
     * entropy source rather than refusing to log.
     *
     * @return string
     */
    public static function new_rid()
    {
        try
        {
            return bin2hex(random_bytes(8));
        }
        catch ( Throwable )
        {
            return substr(md5(uniqid('', true)), 0, 16);
        }
    }

    /**
     * Checks that there is somewhere to write, and turns logging off when there is not.
     *
     * @return void
     * @throws \Exception When a log directory is configured but cannot be written to
     */
    public static function initialize()
    {
        // A stream target needs no directory, and demanding a writable one would be the one thing
        // stopping a container from logging to stdout with no volume mounted at all
        if ( isset(self::STREAMS[(string) config::instance('log')->get('log_type', 'file')]) )
        {
            return;
        }

        $log_path = config::instance('log')->get('log_path');

        // The class can be autoloaded before plato::registry() has run -- static analysis, or an
        // application pulling in a single class. There is no log directory to speak of then, so
        // logging goes quiet instead of throwing in the middle of an autoload
        if ( empty($log_path) && plato::log_path() === '' )
        {
            config::instance('log')->set('log_threshold', self::NONE);
            return;
        }

        $path = $log_path ?: plato::log_path() . DIRECTORY_SEPARATOR;

        if ( !is_dir($path) || !is_writable($path) )
        {
            config::instance('log')->set('log_threshold', self::NONE);

            throw new \Exception('Unable to write the log files. The configured log path "' . $path . '" does not exist or is not writable.');
        }
    }

    /**
     * Whether STDERR is attached to a terminal, which is what makes save() mirror entries through
     * cli:: as well as writing them.
     *
     * @return bool
     */
    public static function is_terminal()
    {
        return defined("STDERR") && is_resource(STDERR) && function_exists('posix_isatty') && posix_isatty(STDERR);
    }

    /**
     * Logs a message with the Error Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function error($msg, $context = null)
    {
        return static::write(self::ERROR, $msg, $context);
    }

    /**
     * Logs a message with the Warning Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function warning($msg, $context = null)
    {
        return static::write(self::WARNING, $msg, $context);
    }

    /**
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool
     */
    public static function warn($msg, $context = null)
    {
        return static::warning($msg, $context);
    }

    /**
     * Logs a message with the Info Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function info($msg, $context = null)
    {
        return static::write(self::INFO, $msg, $context);
    }

    /**
     * Logs a message with the Notice Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function notice($msg, $context = null)
    {
        return static::write(self::NOTICE, $msg, $context);
    }

    /**
     * Logs a message with the Debug Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function debug($msg, $context = null)
    {
        return static::write(self::DEBUG, $msg, $context);
    }

    /**
     * log4j alias of debug()
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool
     */
    public static function trace($msg, $context = null)
    {
        return static::write(self::TRACE, $msg, $context);
    }

    /**
     * log4j alias of critical()
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool
     */
    public static function fatal($msg, $context = null)
    {
        return static::write(self::FATAL, $msg, $context);
    }

    /**
     * Logs a message with the Critical Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function critical($msg, $context = null)
    {
        return static::write(self::CRITICAL, $msg, $context);
    }

    /**
     * Logs a message with the Alert Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function alert($msg, $context = null)
    {
        return static::write(self::ALERT, $msg, $context);
    }

    /**
     * Logs a message with the Emergency Log Level
     *
     * @param   string  $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool    If it was successfully logged
     */
    public static function emergency($msg, $context = null)
    {
        return static::write(self::EMERGENCY, $msg, $context);
    }

    /**
     * Logs a message at an explicit level.
     *
     * @param   int|string                        $level    Level constant, or a caller defined level name
     * @param   string                            $msg      The log message
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data
     * @return  bool
     */
    public static function log($level, $msg, $context = null)
    {
        return static::write($level, $msg, $context);
    }

    /**
     * Logs current memory usage at DEBUG.
     *
     * @param   string  $key  Written to the entry's "tag" context key
     * @return  bool
     */
    public static function memory($key = "memory")
    {
        return static::write(self::DEBUG, str::format_size(memory_get_usage()), $key);
    }

    /**
     * Logs the current timestamp at DEBUG.
     *
     * @param   string  $key  Written to the entry's "tag" context key
     * @return  bool
     */
    public static function time($key = "time")
    {
        return static::write(self::DEBUG, microtime(true), $key);
    }

    /**
     * Queues an entry for its level's file.
     *
     * @param   int|string                        $level    Level constant, or a caller defined level name
     * @param   mixed                             $msg      Objects keep only their property names, arrays become json
     * @param   array<string, mixed>|string|null  $context  Placeholder values and structured data;
     *                                                      a string is shorthand for ['tag' => ...]
     * @return  bool          False when the level does not pass the configured threshold
     * @throws  \Exception    On a numeric level that is not one of the class constants
     */
    public static function write($level, $msg, $context = null)
    {
        // Covers the caller that logs without going through plato::registry(); a booted framework
        // has already been through this and the guard makes it free
        self::boot();

        if ( ! static::_need_logging($level) )
        {
            return false;
        }

        if ( is_object($msg) )
        {
            $msg = self::_object_to_array($msg);
        }

        if ( is_array($msg) )
        {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }

        // Shared context first, so a key passed to this call wins over the one on every entry
        [$msg, $context] = self::_interpolate((string) $msg, array_merge(self::$_shared, self::_context($context)));

        self::$_logs[ $level ][] = ['msg' => $msg, 'context' => $context];

        if ( PHP_SAPI == 'cli' || count(self::$_logs[ $level ]) >= self::$_max_log )
        {
            self::save();
        }

        return true;
    }

    /**
     * Normalises what a caller passed as $context into an array.
     *
     * @param   array<string, mixed>|string|null  $context
     * @return  array<string, mixed>
     */
    private static function _context($context)
    {
        if ( is_array($context) )
        {
            return $context;
        }

        // String context is a category such as 'SQL Error', __METHOD__, or the request method.
        return ($context === null || $context === '') ? [] : ['tag' => (string) $context];
    }

    /**
     * Substitutes `{key}` with its context value, and returns what was left over.
     *
     * @param   string                $message
     * @param   array<string, mixed>  $context
     * @return  array{0: string, 1: array<string, mixed>}
     */
    private static function _interpolate($message, array $context)
    {
        $rest = [];

        foreach ( $context as $key => $value )
        {
            $key = (string) $key;

            // A Throwable is the one context value always worth reading in full, and the one json
            // encoding makes useless -- it walks previous exceptions and the whole trace
            if ( $key === 'exception' && $value instanceof Throwable )
            {
                $rest['exception'] = sprintf(
                    '%s: %s at %s:%d',
                    get_class($value),
                    $value->getMessage(),
                    $value->getFile(),
                    $value->getLine()
                );

                continue;
            }

            $placeholder = '{' . $key . '}';

            if ( strpos($message, $placeholder) !== false && self::_stringable($value) )
            {
                $message = str_replace($placeholder, (string) $value, $message);

                continue;
            }

            $rest[$key] = $value;
        }

        return [$message, $rest];
    }

    /**
     * Whether a context value can stand in for a placeholder.
     *
     * @param   mixed  $value
     * @return  bool
     */
    private static function _stringable($value)
    {
        return $value === null
            || is_scalar($value)
            || (is_object($value) && method_exists($value, '__toString'));
    }

    /**
     * Writes everything buffered and empties the buffer.
     *
     * Registered as a shutdown function by boot(), and called directly by write() on a flush.
     *
     * @return void
     */
    public static function save()
    {
        // Resolve formatting settings once per flush rather than once per line.
        $settings = self::_settings();

        foreach ( self::$_logs as $level => $entries )
        {
            // A level outside self::$levels is caller defined: it still gets a file of its own,
            // but cli:: has no method to render it with
            if ( isset(self::$levels[$level]) )
            {
                $is_sys_log = true;
                $level      = strtolower(self::$levels[$level]);
            }
            else
            {
                $is_sys_log = false;
                $level      = strtolower($level);
            }

            // Not when the target is a stream: cli:: writes to STDOUT, and mirroring there what is
            // already on its way to stdout or stderr prints every entry twice
            if ( $settings['stream'] === null && self::is_terminal() && $is_sys_log )
            {
                foreach ( $entries as $entry )
                {
                    cli::$level($entry['msg']);
                }
            }

            $target   = $settings['stream'] ?? plato::log_path($level . '.log');
            $log_msgs = '';

            foreach ( $entries as $entry )
            {
                $log_msgs .= self::_format_line($level, $entry['msg'], $entry['context'], $settings);
            }

            self::_append($target, $log_msgs);
        }

        self::$_logs = [];
    }

    /**
     * The formatting and destination settings for one flush.
     *
     * @return array{stream: string|null, output: string, datetime: string, rfc3339: string}
     */
    private static function _settings()
    {
        $config = config::instance('log');
        $type   = (string) $config->get('log_type', 'file');

        return [
            'stream'   => self::STREAMS[$type] ?? null,
            'output'   => (string) $config->get('log_output', "%datetime% [%level_name%] --> %message%%context%\n"),
            'datetime' => date((string) $config->get('log_date_format', 'Y-m-d H:i:s')),
            // Machine readable form for the json output. Both are stamped here rather than per line
            // so that a flush of max_log entries costs one date() and not 128
            'rfc3339'  => date(DATE_RFC3339),
        ];
    }

    /**
     * Appends to a log file through a handle held open for the life of the process.
     *
     * @param   string  $file
     * @param   string  $data
     * @return  bool    False when the file could not be opened
     */
    private static function _append($file, $data)
    {
        $handle = self::_handle($file);

        if ( $handle === null )
        {
            return false;
        }

        // flock() on a pipe or a tty is either refused or meaningless depending on the platform,
        // and the thing it protects against does not arise: whoever is collecting stdout is reading
        // a single stream, not a file several processes append to
        if ( self::_is_stream($file) )
        {
            fwrite($handle, $data);

            return true;
        }

        // Still LOCK_EX. Holding the handle removes the open() and the close() from every write,
        // not the guarantee that two writers cannot interleave inside one entry. The lock is also
        // cheaper than the contention it prevents: unsynchronised appends thrash the inode lock
        flock($handle, LOCK_EX);
        fwrite($handle, $data);
        flock($handle, LOCK_UN);

        return true;
    }

    /**
     * Whether $file names a stream rather than a path under the log directory.
     *
     * @param   string  $file
     * @return  bool
     */
    private static function _is_stream($file)
    {
        return in_array($file, self::STREAMS, true);
    }

    /**
     * The append handle for $file, opened on first use and reused afterwards.
     *
     * @param   string  $file
     * @return  resource|null  Null when the file could not be opened
     */
    private static function _handle($file)
    {
        $key   = self::SHARE_PREFIX . $file;
        $entry = runtime::get($key);

        // A handle follows the inode, not the name, so logrotate's default -- rename the live file
        // and create a new one in its place -- would otherwise leave every later entry going into
        // the rotated away file while the new one stayed empty. Same story for a deleted file.
        // The other reason a handle goes stale, a fork, is the registry's own business.
        // A stream is exempt: stat('php://stderr') fails where fstat() on the open handle reports
        // the tty or pipe behind it, so the two would never agree and every line would reopen
        if ( $entry !== null && !self::_is_stream($file) && self::_inode($file) !== $entry['inode'] )
        {
            runtime::forget($key);
            $entry = null;
        }

        if ( $entry === null )
        {
            try
            {
                $entry = runtime::share($key, function () use ($file)
                {
                    return self::_open($file);
                }, function (array $entry)
                {
                    fclose($entry['handle']);
                });
            }
            // Nothing is registered when the factory throws, so a directory that becomes writable
            // later starts working on the next entry rather than staying dead for the process
            catch ( \RuntimeException )
            {
                return null;
            }
        }

        return $entry['handle'];
    }

    /**
     * Open $file for appending.
     *
     * @param   string  $file
     * @return  array{handle: resource, inode: int}
     * @throws  \RuntimeException  When the file cannot be opened
     */
    private static function _open($file)
    {
        $is_stream = self::_is_stream($file);
        $exists    = $is_stream || is_file($file);
        $handle    = fopen($file, 'ab');

        if ( $handle === false )
        {
            throw new \RuntimeException('Unable to open the log file "' . $file . '" for appending.');
        }

        // Apply the configured mode only when the file is created.
        if ( ! $exists )
        {
            @chmod($file, 0777);
        }

        // Nothing reads the inode of a stream, and fstat() on stderr under some SAPIs does not
        // answer at all
        if ( $is_stream )
        {
            return ['handle' => $handle, 'inode' => 0];
        }

        $stat = fstat($handle);

        return [
            'handle' => $handle,
            'inode'  => $stat === false ? 0 : (int) $stat['ino'],
        ];
    }

    /**
     * Inode behind $file right now, 0 when there is no file there.
     *
     * @param   string  $file
     * @return  int
     */
    private static function _inode($file)
    {
        clearstatcache(true, $file);

        $stat = @stat($file);

        return $stat === false ? 0 : (int) $stat['ino'];
    }

    /**
     * Reduces an object to its property names and their visibility.
     *
     * Records ":private" / ":protected" / ":public" in place of every value, so logging an object
     * writes its shape and never its contents -- a token or password held on a model does not
     * reach the log file because someone passed the whole object to log::debug().
     *
     * @param   object  $obj
     * @return  array   Single entry keyed by class name
     */
    private static function _object_to_array($obj)
    {
        $arr        = [];
        $class      = new \ReflectionClass($obj);
        $properties = $class->getProperties();

        foreach ( $properties as $property )
        {
            $arr[$property->getName()] = $property->isPrivate() ? ':private'
                : ($property->isProtected() ? ':protected' : ':public');
        }

        return [$class->getName() => $arr];
    }

    /**
     * Renders one line from the log_output template.
     *
     * log_output = 'json' is not a template but a sentinel: it writes one json object per line, with
     * the leftover context as fields beside ts / level / msg rather than json nested inside a
     * string. That is the form a collector reads, and the reason the timestamp is RFC 3339 there
     * instead of log_date_format -- a machine wants the offset, a human reading a file does not.
     *
     * @param   string                $level     Level name, uppercased into %level_name%
     * @param   string                $msg       The log message
     * @param   array<string, mixed>  $context   What interpolation did not consume
     * @param   array{stream: string|null, output: string, datetime: string, rfc3339: string}  $settings
     * @return  string  Carries whatever the template ends with, the newline included
     */
    private static function _format_line($level, $msg, array $context, array $settings)
    {
        $level = strtoupper($level);

        if ( $settings['output'] === 'json' )
        {
            // ts / level / msg are written first and win a name clash: a context key called "msg"
            // must not be able to hide the message
            $record = ['ts' => $settings['rfc3339'], 'level' => $level, 'msg' => $msg] + $context;

            return (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        // The space belongs to the value, not to the template: a line with no leftover context
        // should not end in a stray separator
        $rendered = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);

        return str_replace(
            ['%datetime%', '%level_name%', '%message%', '%context%'],
            [$settings['datetime'], $level, $msg, $rendered],
            $settings['output']
        );
    }

    /**
     * Whether $level passes log_threshold.
     *
     * @param   int|string  $level
     * @return  bool
     * @throws  \Exception  On a numeric level that is not one of the class constants
     */
    protected static function _need_logging($level)
    {
        $loglabels = config::instance('log')->get('log_threshold');

        if ( $loglabels == self::NONE )
        {
            return false;
        }

        if ( $loglabels == self::ALL )
        {
            return true;
        }

        // A bare level stands for itself and everything more severe
        if ( ! is_array($loglabels) )
        {
            $a = [];

            foreach ( array_keys(self::$levels) as $l )
            {
                if ( $l >= $loglabels )
                {
                    $a[] = $l;
                }
            }

            $loglabels = $a;
        }

        // Only this local copy is remapped. write() keys the buffer by the level it was handed,
        // which is what gives a caller defined level a file of its own while still weighing it
        // against the threshold as NOTICE
        if ( is_string($level) )
        {
            if ( ! $level = array_search($level, self::$levels) )
            {
                $level = self::NOTICE;
            }
        }

        // $level is an int by now either way, so there is nothing left to check but membership
        if ( ! isset(self::$levels[$level]) )
        {
            throw new \Exception('Invalid level "' . $level . '" passed to logger()');
        }

        return in_array($level, $loglabels);
    }
}
