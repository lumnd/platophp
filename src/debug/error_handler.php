<?php

/**
 * Global handler for PHP errors, uncaught exceptions and shutdown
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\debug;

use plato\cli;
use plato\config;
use plato\exception\controller_exception;
use plato\exception\plato_exception;
use plato\exception\request_exception;
use plato\exception\route_exception;
use plato\http\req;
use plato\http\reply;
use plato\http\resp;
use plato\log;
use plato\plato;
use plato\runtime;
use plato\security\security;
use plato\str;
use plato\tpl;

/**
 * Collects everything PHP reports and renders it once, at the end of the request
 *
 * plato::run() wires the three entry points:
 *
 *     register_shutdown_function(['plato\debug\error_handler', 'shutdown_handler']);
 *     set_error_handler(['plato\debug\error_handler', 'error_handler'], E_ALL);
 *     set_exception_handler(['plato\debug\error_handler', 'exception_handler']);
 *
 * Recoverable PHP errors are logged and may be appended to the HTML debug panel. Uncaught
 * exceptions are logged and converted into one client-safe reply; shutdown never appends a panel
 * after a response body has been sent.
 *
 * The buffer is rendered only when the request is allowed to see debug output: plato::debug()
 * is on or the client IP is listed in the security.safe_client_ip config, and the request has
 * not opted out through debug_hidden().
 */
class error_handler
{
    /**
     * Exit status of a command line process killed by an uncaught exception.
     *
     * 255 rather than 1: it is what PHP itself exits with when no handler is installed, so adding
     * this one does not change what a caller reading `$?` sees.
     */
    public const CLI_FAILURE = 255;

    /**
     * Whether the client IP is whitelisted, which shows debug output even with debug off.
     *
     * @var bool
     */
    private static $_debug_safe_ip = false;

    /** @var string */
    private static $_debug_error_msg = '';

    /** @var string|float Empty until test_debug_mt() stores the first microtime() reading */
    private static $_debug_mt_time = '';

    /** @var string */
    private static $_debug_mt_info = '';

    /**
     * Set by debug_hidden() to suppress the panel for a single request.
     *
     * @var bool
     */
    private static $_debug_hidden = false;

    /**
     * Labels used in the debug panel, keyed by error level.
     *
     * E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR and E_COMPILE_WARNING can
     * never reach a handler installed with set_error_handler(); they are listed for reference.
     *
     * @var array
     */
    private static $_debug_errortype = [
        E_WARNING         => "<font color='#CDA93A'>Warning</font>",
        E_NOTICE          => "<font color='#CDA93A'>Notice</font>",
        E_USER_ERROR      => "<font color='#D63107'>User error</font>",
        E_USER_WARNING    => "<font color='#CDA93A'>User warning</font>",
        E_USER_NOTICE     => "<font color='#CDA93A'>User notice</font>",
        E_ERROR           => "Fatal error",
        E_PARSE           => "Parse error",
        E_CORE_ERROR      => "Core error",
        E_CORE_WARNING    => "Core warning",
        E_COMPILE_ERROR   => "Compile error",
        E_COMPILE_WARNING => "Compile warning"
    ];

    /**
     * Decide whether this request may see debug output.
     *
     * plato::_bootstrap() calls this once during bootstrap, right after the handlers are registered.
     * Public and repeatable because the answer belongs to the request rather than the process. A
     * resident process must not let one safe request open the debug panel for later requests.
     *
     * @return void
     */
    public static function capture()
    {
        // Read through security::config() rather than reading the same `security` section a second
        // time: one class owns one section, and an application that overrode it through
        // security::configure() means that override to apply here too
        self::$_debug_safe_ip = in_array(req::ip(), (array) security::config('safe_client_ip'));
    }

    /**
     * Clear state that belongs to the previous request of a resident process.
     */
    public static function reset(): void
    {
        self::$_debug_safe_ip   = false;
        self::$_debug_error_msg = '';
        self::$_debug_mt_time   = '';
        self::$_debug_mt_info   = '';
        self::$_debug_hidden    = false;
    }

    /**
     * Keep the debug panel out of this response even when debug output is allowed.
     *
     * Meant for ajax / api actions, whose callers cannot cope with the extra payload.
     *
     * @param bool $hidden
     *
     * @return void
     */
    public static function debug_hidden($hidden = true)
    {
        self::$_debug_hidden = $hidden;
    }

    /**
     * Registered with register_shutdown_function() by plato::run()
     *
     * The HTML error template is emitted only when no reply has already been sent. Deferred runtime
     * callbacks run last, past fastcgi_finish_request().
     *
     * @return void
     */
    public static function shutdown_handler()
    {
        if ( req::method() == 'CLI' )
        {
            return;
        }

        benchmark::mark('loading_time:_base_classes_end');

        if ( !resp::sent() )
        {
            tpl::output();
            self::show_error();
        }

        runtime::shutdown_function(null, [], true);
    }

    /**
     * Handler installed with set_error_handler().
     *
     * trigger_error() and engine-raised errors arrive here directly and do not stop execution.
     *
     * PHP 8 no longer passes the error context to error handlers, so $errcontext needs a
     * default value.
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     * @param array  $errcontext
     *
     * @return bool
     */
    public static function error_handler($errno, $errstr, $errfile, $errline, $errcontext = [])
    {
        if ( (error_reporting() & $errno) === 0 )
        {
            return false;
        }

        $err = self::format_errstr($errno, $errstr, $errfile, $errline, $errcontext);

        if ( $err != '@' )
        {
            log::debug("\nError Trace:\n" . self::strip_tags($err));

            // CLI has no page to append to, the debug log above is the whole output
            if ( PHP_SAPI != 'cli' )
            {
                self::$_debug_error_msg .= $err;
            }
        }

        return true;
    }

    /**
     * Handler installed with set_exception_handler()
     *
     * Reached only when nobody caught the exception, which is the end of the process either way:
     * PHP stops execution as soon as this returns. What differs by SAPI is where the failure is
     * reported and what the caller is told.
     *
     * **Under CLI it has to be both loud and non zero.** Installing a handler at all is what makes
     * that a decision: with no handler PHP prints `PHP Fatal error: Uncaught ...` and exits 255,
     * and a handler that only writes the log file leaves a script that died half way through
     * printing nothing and exiting 0 -- a caller, a cron mail or a CI step then reads it as
     * success. So the message goes to STDERR and the process exits with PHP's own uncaught
     * exception status, which is exactly what would have happened without this class.
     *
     * That does not cost a resident worker anything it was not already losing. `bin/plato` and
     * plato\server\dispatcher both catch their own exceptions -- the console turns one into an exit
     * code, the dispatcher into a single error frame and keeps serving -- so an exception that
     * gets this far is one no boundary claimed, and the process was going down with or without
     * the exit() below.
     *
     * CLI logs and reports without building a reply at all. There is no response for one to become,
     * and exception_reply() asks the application for its error page: rendering an html page nobody
     * will read would run application code -- a template engine, the queries behind an error
     * layout -- on the way out of a process that is already dying.
     *
     * @param \Throwable $e
     *
     * @return void
     */
    public static function exception_handler($e)
    {
        if ( PHP_SAPI === 'cli' )
        {
            self::_log_exception($e, self::_status($e), self::_message($e));
            self::_report_to_stderr($e);

            exit(self::CLI_FAILURE);
        }

        $reply = self::exception_reply($e);

        if ( !resp::sent() )
        {
            resp::send($reply);
        }
    }

    /**
     * Write an uncaught exception where a command line caller will see it.
     *
     * Through cli::stderr() when the console has opened the standard streams, so a test or a
     * daemon that redirected them is honoured; through the constant otherwise, because this may
     * run before -- or instead of -- cli::boot().
     *
     * @param \Throwable $e
     *
     * @return void
     */
    private static function _report_to_stderr(\Throwable $e): void
    {
        $handle = cli::booted() ? cli::stderr() : (defined('STDERR') ? STDERR : null);

        if ( !is_resource($handle) )
        {
            return;
        }

        fwrite($handle, sprintf(
            'PHP Fatal error:  Uncaught %s: %s in %s:%d%s%s%s',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString(),
            PHP_EOL
        ));
    }

    /**
     * Log an exception and turn it into one client-safe HTTP response.
     *
     * This is the only failure path an application cannot reach with middleware. Routing has to
     * succeed before there is a route to look middleware up for, so "this controller does not
     * exist" and "this action is not routable" are raised before the pipeline is built and land
     * here instead -- which is why an application with a styled error page still used to answer a
     * mistyped url with text/plain naming an internal class.
     *
     * The `error_handle` callback configured through plato::registry() is what closes that gap. It
     * is handed the throwable and the status this class resolved it to, and answers with a reply,
     * or with null to keep the default below.
     */
    public static function exception_reply(\Throwable $e): reply
    {
        $status = self::_status($e);
        $errstr = self::_message($e);

        self::_log_exception($e, $status, $errstr);

        // The exception response is complete; the shutdown handler must not append its debug panel.
        self::$_debug_hidden = true;

        return self::_delegated_reply($e, $status) ?? self::_default_reply($status, $errstr);
    }

    /**
     * The exception's message, with a framework error code expanded into its template.
     *
     * @param \Throwable $e
     *
     * @return string
     */
    private static function _message(\Throwable $e): string
    {
        return $e instanceof plato_exception
            ? $e->getMessage()
            : self::fmt_code((int) $e->getCode(), $e->getMessage());
    }

    /**
     * Write an uncaught exception to the log, at the level its status deserves.
     *
     * A 4xx is the client having asked for something that is not there, is not allowed, or was
     * malformed. It gets one line at warning: reading the offending file off disk and walking a
     * backtrace to describe a mistyped url costs more than the line is worth, and an error log
     * where every crawler hit leaves a stack trace is one nobody reads the real incidents out of.
     *
     * A 5xx keeps the full trace at error level, which is what it was always for.
     *
     * @param \Throwable $e
     * @param int        $status
     * @param string     $errstr
     *
     * @return void
     */
    private static function _log_exception(\Throwable $e, int $status, string $errstr)
    {
        if ( $status < 500 )
        {
            // The request goes in the context rather than in the message: the path is whatever the
            // client sent, and a log line built by concatenation is one nobody can group by
            log::warning($status . ' -- ' . $errstr, [
                'method' => req::method(),
                'path'   => req::path(),
            ]);

            return;
        }

        $err = self::format_errstr((int) $e->getCode(), $errstr, $e->getFile(), $e->getLine(), $e->getTrace());

        log::error("\nException Trace:\n" . ($err === '@' ? $errstr : self::strip_tags($err)));
    }

    /**
     * Ask the application's error_handle callback what this failure looks like.
     *
     * It runs inside a try / catch because it renders: a template that is missing, or an error page
     * that throws on the way to describing an error, must not be the reason the request answers
     * nothing at all. The secondary failure is logged as its own incident, because a hook that
     * throws on every 404 is otherwise invisible.
     *
     * @param \Throwable $e
     * @param int        $status
     *
     * @return reply|null Null when nothing is configured, the hook declined, or the hook failed
     */
    private static function _delegated_reply(\Throwable $e, int $status): ?reply
    {
        $handle = plato::config('error_handle');

        if ( $handle === null || !is_callable($handle) )
        {
            return null;
        }

        // resp queues a response on the class rather than on an object, so a hook that set a header
        // or a cookie and then threw half way through leaves that queued. Without putting the
        // snapshot back, the fallback below answers carrying pieces of a response nobody sent.
        $pending = resp::pending();

        try
        {
            $reply = call_user_func($handle, $e, $status);
        }
        catch ( \Throwable $failed )
        {
            resp::restore($pending);

            log::error('error_handle failed while rendering ' . $status . ': ' . $failed->getMessage(), [
                'exception' => get_class($failed),
                'file'      => $failed->getFile() . ':' . $failed->getLine(),
            ]);

            return null;
        }

        return $reply instanceof reply ? $reply : null;
    }

    /**
     * The response an application that configured no error_handle callback gets.
     *
     * @param int    $status
     * @param string $errstr
     *
     * @return reply
     */
    private static function _default_reply(int $status, string $errstr): reply
    {
        $detail = plato::debug() || self::$_debug_safe_ip
            ? $errstr
            : self::_status_message($status);

        if ( req::is_json() )
        {
            return resp::status($status)
                ->type('application/json')
                ->response_error(-$status, $detail);
        }

        return resp::status($status)
            ->type('text/plain')
            ->text($detail);
    }

    /**
     * Flush the collected errors, called only by shutdown_handler()
     *
     * For a JSON request this writes a second JSON document behind the one the controller
     * already sent, which the client cannot decode; debug_hidden() is the way out for actions
     * where that matters. CLI never reaches this method because the buffer stays empty there.
     *
     * @return void
     */
    public static function show_error()
    {
        if ( self::$_debug_error_msg != '' || self::$_debug_mt_info != '' )
        {
            if ( ( plato::debug() || self::$_debug_safe_ip === true ) && !self::$_debug_hidden )
            {
                if ( req::is_json() )
                {
                    resp::send(resp::status(500)->response_error(-500, self::$_debug_error_msg));
                }
                else
                {
                    $js  = '<script language=\'javascript\'>';
                    $js .= 'function debug_close_all() {';
                    $js .= '    document.getElementById(\'debug_ctl\').style.display=\'none\';';
                    $js .= '    document.getElementById(\'debug_errdiv\').style.display=\'none\';';
                    $js .= '}</script>';
                    echo $js;
                    echo '<div id="debug_ctl" style="width:100px;line-height:18px;position:absolute;top:2px;left:2px;border:1px solid #ccc; padding:1px;text-align:center">' . "\n";
                    echo '<a href="javascript:;" onclick="javascript:document.getElementById(\'debug_errdiv\').style.display=\'block\';" style="font-size:12px;">[Show debug info]</a>' . "\n";
                    echo '</div>' . "\n";
                    echo '<div id="debug_errdiv" style="z-index:9999;width:80%;position:absolute;top:10px;left:8px;border:2px solid #ccc; background: #fff; padding:8px;display:none">';
                    echo '<div style="line-height:24px; background: #FBFEEF;;"><div style="float:left"><strong>PlatoPHP error / warning trace:</strong></div><div style="float:right"><a href="javascript:;" onclick="javascript:debug_close_all();" style="font-size:12px;">[Close all]</a></div>';
                    echo '<br style="clear:both"/></div>';
                    echo self::$_debug_error_msg;
                    echo "<hr /><div>";
                    echo "<strong>Performance trace:</strong><br />" . self::$_debug_mt_info . "</div>\n";
                    echo '<br style="clear:both"/></div>';
                }
            }
        }
    }

    /**
     * Render one error into the block that show_error() prints
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     * @param array  $errcontext Exception trace when the error came from exception_handler()
     *
     * @return string HTML block, plain text for JSON requests, or '@' when the error is to be
     *                dropped: the file is gone, or the line is suppressed with the @ operator.
     *                A custom error handler still runs for suppressed errors, so the only way
     *                to spot one is to read the offending line back from disk.
     */
    public static function format_errstr($errno, $errstr, $errfile, $errline, $errcontext)
    {
        $user_errors = [ E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE ];

        // A user-level error carrying an exception in its context was raised on behalf of that
        // exception, so report where the exception came from, not the trigger_error() site
        if ( in_array($errno, $user_errors) )
        {
            foreach ( $errcontext as $e )
            {
                if ( is_object($e) && method_exists($e, 'getMessage') )
                {
                    $errno      = $e->getCode();
                    $errstr     = $errstr . ' ' . $e->getMessage();
                    $errline    = $e->getLine();
                    $errfile    = $e->getFile();
                    $errcontext = $e->getTrace();
                }
            }
        }

        if ( !is_file($errfile) )
        {
            return '@';
        }

        $fp = fopen($errfile, 'r');
        $n = 0;
        $errline_str = '';
        while ( !feof($fp) )
        {
            $line = fgets($fp, 1024);
            $n++;
            if ( $n == $errline )
            {
                $errline_str = trim($line);
                break;
            }
        }
        fclose($fp);

        if ( $errline_str !== ''
            && ($errline_str[0] === '@' || preg_match("/[\(\t ]@/", $errline_str)) )
        {
            return '@';
        }

        // A json client has no use for the html block
        if ( req::is_json() )
        {
            return $errstr . ' in ' . $errfile . ' ' . $errline;
        }

        if ( !isset(self::$_debug_errortype[$errno]) )
        {
            self::$_debug_errortype[$errno] = "<font color='#466820'>Raised by hand</font>";
        }

        $err = "<div style='font-size:14px;line-height:160%;border-bottom:1px dashed #ccc;margin-top:8px;'>\n";
        $err .= "Where: " . date("Y-m-d H:i:s", time()) . '::' . req::path() . "<br />\n";
        $err .= "Type: " . self::$_debug_errortype[$errno] . "<br />\n";
        $err .= "Message: <font color='#3F7640'>" . $errstr . "</font><br />\n";
        $err .= "Location: <a href=\"" . str_replace([ '%file','%line' ], [ $errfile, $errline ], plato::editor()) . "\">" . $errfile . "</a> line {$errline}<br />\n";
        $err .= "Source: <font color='#747267'>" . htmlspecialchars($errline_str) . "</font><br />\n";
        $err .= "Trace:<br />\n";

        $backtrace = debug_backtrace();
        array_shift($backtrace);
        $narr = [ 'class', 'type', 'function', 'file', 'line' ];
        foreach ( $backtrace as $i => $trace )
        {
            foreach ( $narr as $k )
            {
                if ( !isset($trace[$k]) )
                { $trace[$k] = '';
                }
            }
            $err .= "<font color='#747267'>[$i] in function {$trace['class']}{$trace['type']}{$trace['function']} ";
            if ( $trace['file'] )
            { $err .= " in {$trace['file']} ";
            }
            if ( $trace['line'] )
            { $err .= " on line {$trace['line']} ";
            }
            $err .= "</font><br />\n";
        }

        $err .= "<span></span></div>\n";

        return $err;
    }

    /**
     * Turn a block built by format_errstr() back into plain text for the log
     *
     * The patterns are tied to the exact markup format_errstr() emits; changing one means
     * changing the other.
     *
     * @param mixed $errstr
     *
     * @return string
     */
    public static function strip_tags($errstr)
    {
        $errstr = preg_replace("/<font([^>]*)>|<\/font>|<\/div>|<\/strong>|<strong>|<br \/>/iU", '', $errstr);
        $errstr = preg_replace("/<div style='font-size:14px([^>]*)>/iU", "-----------------------------------------------\nError trace:", $errstr);
        $errstr = preg_replace("/<span><\/span>/iU", "-----------------------------------------------", $errstr);
        $errstr = str_replace([ "-&lt;", "&gt;" ], [ "<", ">" ], $errstr);
        $errstr = strip_tags($errstr);

        return $errstr;
    }

    /**
     * Expand an exception code into the message template registered in config/exception.php
     *
     * The exception message doubles as the template arguments: a serialized array fills a
     * multi-placeholder template, a plain string fills a single one. Codes with no template
     * fall through and the message is returned untouched.
     *
     * @param int    $errno
     * @param string $errstr
     *
     * @return string
     */
    public static function fmt_code($errno, $errstr)
    {
        $msgtpl = config::instance('exception')->get($errno);
        if ( empty($msgtpl) )
        {
            return $errstr;
        }

        // An error message is format arguments, never an object graph, so no classes are allowed
        $msg  = str::is_serialized($errstr) ? unserialize($errstr, ['allowed_classes' => false]) : $errstr;
        if ( !is_array($msg) )
        {
            $msg = [$msg];
        }

        return vsprintf($msgtpl, $msg);
    }

    /**
     * HTTP status represented by an uncaught exception.
     */
    private static function _status(\Throwable $e): int
    {
        if ( $e instanceof route_exception )
        {
            return $e->status();
        }

        if ( $e instanceof request_exception )
        {
            return $e->status();
        }

        if ( $e instanceof controller_exception )
        {
            return 404;
        }

        return 500;
    }

    /**
     * Public message for an error response when debug details are disabled.
     */
    private static function _status_message(int $status): string
    {
        return [
            400 => 'Bad Request',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        ][$status] ?? 'Request Failed';
    }

    /**
     * Record a memory / elapsed-time checkpoint in the panel's performance section
     *
     * Call it repeatedly around the code under inspection; the first call only takes a memory
     * reading, later ones also report the time since the previous call. Does nothing unless
     * the request is allowed to see debug output.
     *
     * @param mixed $optmsg Label shown next to the reading
     *
     * @return void
     */
    public static function test_debug_mt($optmsg)
    {
        if ( plato::debug() || self::$_debug_safe_ip )
        {
            if ( empty(self::$_debug_mt_time) )
            {
                self::$_debug_mt_time = microtime(true);
                $m = sprintf('%0.2f', memory_get_usage() / 1024 / 1024);
                self::$_debug_mt_info = "{$optmsg}: memory {$m} MB<br />\n";
            }
            else
            {
                $cutime = microtime(true);
                $etime = sprintf('%0.4f', $cutime - self::$_debug_mt_time);
                $m = sprintf('%0.2f', memory_get_usage() / 1024 / 1024);
                self::$_debug_mt_info .= "{$optmsg}: memory {$m} MB, {$etime} s since the last mark<br />\n";
                self::$_debug_mt_time = $cutime;
            }
        }
    }
}
