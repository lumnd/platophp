<?php

/**
 * The request boundary of a resident server worker: one message in, one ct / ac dispatch out
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\server;

use plato\exception\server_exception;
use plato\http\reply;
use plato\log;
use plato\plato;
use Throwable;

/**
 * Turns one inbound message into a controller call, and cleans up after it.
 *
 * This is the part of a socket server that belongs to a framework, and the only part. A driver hands
 * it a payload; it decodes the payload, wipes what the previous message left behind, routes to
 * `ctl_{ct}::{ac}()` through `plato::handle()`, collects its reply, and hands
 * that back for the driver to write to the socket:
 *
 *     $reply = dispatcher::handle($conn, $raw);
 *
 *     if ( $reply !== null )
 *     {
 *         $conn->send($reply);
 *     }
 *
 * `$raw` is one whole application message and never a piece of one. Assembling it out of whatever
 * the socket delivered is the driver's half of the contract, see server\driver.
 *
 * An action reached this way is an ordinary action. It reads its input through `req::get()`, asks
 * who the caller is through `plato::$auth`, and may return a `plato\http\reply`.
 *
 * **The request boundary.** A php-fpm process forgets everything when the request ends; a resident
 * worker forgets nothing, so every static property holding request state is a leak from the previous
 * message into this one. `plato::reset_request()` owns that list and says what each entry leaks
 * without it; this class calls it before every dispatch and adds one thing of its own:
 *
 *   plato::$auth   set from the connection, not from the payload. The identity of a client on a long
 *                  lived connection is established once, at open, and belongs to the connection;
 *                  leaving the previous message's value in place hands one client another's session
 *
 * **What it does not do.** It has no opinion on the transport or the protocol (that is
 * server\driver), it cannot reach a connection held by another worker (that is a channel over redis,
 * not a static array), and it does not authenticate anybody -- the open hook does, once per
 * connection.
 */
class dispatcher
{
    /**
     * Wire codes of an error reply. Not keys of config/exception.php: these go to a client, and
     * they share the space `resp::response_error()` uses, where anything but 0 is a failure.
     */
    public const CODE_BAD_MESSAGE = -1;
    public const CODE_NOT_FOUND = -2;
    public const CODE_REFUSED   = -3;
    public const CODE_INTERNAL  = -4;

    /**
     * Hooks an application can bind, see on().
     */
    public const HOOKS = ['open', 'message', 'close', 'error'];

    /**
     * Settings this class reads, and their defaults. The `dispatch` key of a config/server.php server.
     */
    public const DEFAULTS = [
        'ct_key'       => 'ct',
        'ac_key'       => 'ac',
        'seq_key'      => 'seq',
        'max_payload'  => 65536,
        'reply_echo'   => true,
        'error_reply'  => true,
        'error_detail' => false,
    ];

    /**
     * Active settings, null until config() resolves them.
     *
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * Bound hooks, name => handle => callable.
     *
     * @var array<string, array<int, callable>>
     */
    private static $_hooks = [];

    /**
     * Handle of the last bound hook.
     *
     * @var int
     */
    private static $_hh = 0;

    /**
     * Decodes a message payload into an array, null for the built in json codec.
     *
     * @var callable|null
     */
    private static $_decoder = null;

    /**
     * Encodes a reply, null for the built in json codec.
     *
     * @var callable|null
     */
    private static $_encoder = null;

    /**
     * Connection being served right now, null between messages.
     *
     * @var connection|null
     */
    private static $_current = null;

    /**
     * Correlation id of the message being served, '' when it carried none.
     *
     * @var string
     */
    private static $_seq = '';

    /**
     * Route of the message being served, for the shutdown guard's log line.
     *
     * @var string
     */
    private static $_route = '';

    /**
     * Whether the shutdown guard has been installed.
     *
     * @var bool
     */
    private static $_guarded = false;

    /**
     * Hand the dispatcher its settings instead of letting it read config/server.php.
     *
     * Merged onto DEFAULTS rather than onto config(): these settings arrive from
     * server::start(), which has already resolved whose `dispatch` block applies. Falling
     * back to the file here would let the previous server's keys survive into the new one.
     *
     * @param array<string, mixed> $config Same shape as the `dispatch` key of a server
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + self::DEFAULTS;
    }

    /**
     * Drop the settings, so the next message resolves them again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$_config = null;
    }

    /**
     * The active settings, resolved on the first message that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            $settings = [];

            try
            {
                $settings = (array) (server::settings()['dispatch'] ?? []);
            }
            catch ( Throwable )
            {
                // No server configuration at all is not an error here: a driver that builds the
                // dispatcher itself is free to skip config/server.php, and DEFAULTS are serviceable
            }

            self::$_config = $settings + self::DEFAULTS;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Accept a connection, or refuse it.
     *
     * The place an application authenticates a client: an open hook reads whatever the
     * driver put on the connection during the handshake, decides, and stores the identity with
     * `$conn->set(connection::AUTH, $user)` so that every later message is dispatched as that user.
     *
     * @param connection $conn
     *
     * @return bool  False when a hook refused the connection; the driver has to close it
     */
    public static function open(connection $conn): bool
    {
        self::_guard();

        foreach ( self::_hooks('open') as $hook )
        {
            try
            {
                if ( call_user_func($hook, $conn) === false )
                {
                    return false;
                }
            }
            catch ( Throwable $e )
            {
                self::_report($conn, $e, []);

                return false;
            }
        }

        return true;
    }

    /**
     * Serve one message.
     *
     * Never throws: a message that fails takes neither the connection nor the worker with it, and
     * the other clients of this process did nothing wrong. Failures come back as an error reply, or
     * as null when `error_reply` is off.
     *
     * @param connection $conn
     * @param string     $raw  Message payload as the driver received it, whole and already unframed
     *
     * @return string|null  Payload to send back, null when there is nothing to say
     */
    public static function handle(connection $conn, string $raw)
    {
        self::_guard();
        self::$_current = $conn;

        try
        {
            self::_reset($conn);

            $max = (int) self::config('max_payload');

            if ( $max > 0 && strlen($raw) > $max )
            {
                return self::error(self::CODE_BAD_MESSAGE, 'payload too large');
            }

            $msg = [];

            try
            {
                $msg = self::decode($raw);
            }
            catch ( Throwable $e )
            {
                self::_report($conn, $e, []);

                return self::error(self::CODE_BAD_MESSAGE, 'malformed payload');
            }

            self::$_seq = (string) ($msg[self::config('seq_key')] ?? '');

            try
            {
                return self::_serve($conn, $msg);
            }
            catch ( Throwable $e )
            {
                self::_report($conn, $e, $msg);

                return self::error(
                    self::CODE_INTERNAL,
                    self::config('error_detail') ? $e->getMessage() : 'internal error'
                );
            }
        }
        catch ( Throwable $e )
        {
            self::_report($conn, $e, []);

            return self::error(
                self::CODE_INTERNAL,
                self::config('error_detail') ? $e->getMessage() : 'internal error'
            );
        }
        finally
        {
            self::$_current = null;
            self::$_route   = '';
            self::$_seq     = '';
            plato::$auth    = null;
        }
    }

    /**
     * Note that a connection is gone.
     *
     * @param connection $conn
     *
     * @return void
     */
    public static function close(connection $conn): void
    {
        $conn->mark_closed();

        foreach ( self::_hooks('close') as $hook )
        {
            try
            {
                call_user_func($hook, $conn);
            }
            catch ( Throwable $e )
            {
                // A teardown hook that throws has nowhere to report to but the log: the socket is
                // already gone, so there is nobody left to answer
                self::_report($conn, $e, []);
            }
        }
    }

    /**
     * The connection being served, for an action that has to reach its own client.
     *
     * Null outside a dispatch, which is also how an action tells it was reached over http.
     *
     * @return connection|null
     */
    public static function current()
    {
        return self::$_current;
    }

    /**
     * Correlation id of the message being served, '' when it carried none.
     *
     * A client that has several requests in flight on one socket pairs answers with requests by it,
     * so an action that answers out of band -- later, from a queue worker -- has to carry it along.
     *
     * @return string
     */
    public static function seq(): string
    {
        return self::$_seq;
    }

    /**
     * Bind a hook.
     *
     *   open     fn(connection $c): bool   authenticate; false refuses the connection
     *   message  fn(connection $c, array $msg): mixed   before routing; a non null return is the
     *            reply and the dispatch does not happen, which is how an application level ping is
     *            answered without a controller
     *   close    fn(connection $c): void
     *   error    fn(connection $c, Throwable $e, array $msg): void   after it has been logged
     *
     * @param string   $hook One of HOOKS
     * @param callable $cb
     *
     * @return int  Handle, pass it to off()
     * @throws server_exception When $hook is not a hook
     */
    public static function on(string $hook, callable $cb): int
    {
        if ( !in_array($hook, self::HOOKS, true) )
        {
            throw new server_exception(sprintf(
                'there is no server hook "%s"; it is one of %s',
                $hook,
                implode(', ', self::HOOKS)
            ));
        }

        self::$_hooks[$hook][++self::$_hh] = $cb;

        return self::$_hh;
    }

    /**
     * Unbind a hook.
     *
     * @param int $handle Value returned by on()
     *
     * @return bool
     */
    public static function off(int $handle): bool
    {
        foreach ( self::$_hooks as $hook => $bound )
        {
            if ( isset($bound[$handle]) )
            {
                unset(self::$_hooks[$hook][$handle]);

                return true;
            }
        }

        return false;
    }

    /**
     * Replace the wire codec.
     *
     * The built in one is json in both directions. A binary protocol -- protobuf, msgpack -- passes
     * a pair of callables instead; the decoder returns an array carrying the ct / ac keys, the
     * encoder takes anything and returns a string. Either may be null to keep the json one.
     *
     * @param callable|null $decoder fn(string $raw): array
     * @param callable|null $encoder fn(mixed $data): string
     *
     * @return void
     */
    public static function set_codec(?callable $decoder, ?callable $encoder): void
    {
        self::$_decoder = $decoder;
        self::$_encoder = $encoder;
    }

    /**
     * Decode a message payload into the array `plato::run()` routes on.
     *
     * @param string $raw
     *
     * @return array<string, mixed>
     * @throws server_exception When the payload is not an object the codec can read
     */
    public static function decode(string $raw): array
    {
        if ( self::$_decoder !== null )
        {
            return (array) call_user_func(self::$_decoder, $raw);
        }

        $data = json_decode($raw, true);

        if ( !is_array($data) )
        {
            throw new server_exception(sprintf(
                'a server message has to carry a json object, %s given',
                $raw === '' ? 'an empty payload' : gettype($data)
            ));
        }

        return $data;
    }

    /**
     * Encode a reply.
     *
     * A string is already a payload and passes through untouched, so an action that printed json
     * does not get it encoded twice.
     *
     * @param mixed $data
     *
     * @return string
     */
    public static function encode($data): string
    {
        if ( self::$_encoder !== null )
        {
            return (string) call_user_func(self::$_encoder, $data);
        }

        if ( is_string($data) )
        {
            return $data;
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build an error reply, in the shape `resp::response()` uses for an http answer.
     *
     * @param int         $code One of the CODE_ constants
     * @param string      $msg  Text for the client; never anything it did not already know
     * @param string|null $seq  Correlation id, the one being served when omitted
     *
     * @return string|null  Null when `error_reply` is off
     */
    public static function error(int $code, string $msg, ?string $seq = null)
    {
        if ( !self::config('error_reply') )
        {
            return null;
        }

        $seq   = $seq ?? self::$_seq;
        $reply = [
            'code'      => $code,
            'msg'       => $msg,
            'data'      => [],
            'timestamp' => plato::timestamp(),
        ];

        if ( $seq !== '' )
        {
            $reply[self::config('seq_key')] = $seq;
        }

        return self::encode($reply);
    }

    /**
     * Route one decoded message and collect what the action printed.
     *
     * @param connection           $conn
     * @param array<string, mixed> $msg
     *
     * @return string|null
     */
    private static function _serve(connection $conn, array $msg)
    {
        foreach ( self::_hooks('message') as $hook )
        {
            $answer = call_user_func($hook, $conn, $msg);

            if ( $answer !== null )
            {
                return self::encode($answer);
            }
        }

        $ct = (string) ($msg[self::config('ct_key')] ?? '');
        $ac = (string) ($msg[self::config('ac_key')] ?? '');

        self::$_route = ($ct === '' ? '?' : $ct) . '/' . ($ac === '' ? '?' : $ac);

        // run() reads ct / ac out of the payload under the keys it owns, so a codec that spells them
        // differently is normalised here rather than in the router. A key that is absent stays
        // absent: run() falls back to the router's defaults on `??`, which an empty string defeats.
        unset($msg['ct'], $msg['ac']);

        if ( $ct !== '' )
        {
            $msg['ct'] = $ct;
        }

        if ( $ac !== '' )
        {
            $msg['ac'] = $ac;
        }

        $level = ob_get_level();
        ob_start();

        try
        {
            $routed = plato::handle($msg);
        }
        finally
        {
            $out = '';

            // Innermost first, so an action that opened a buffer and forgot to close it still has
            // its output collected in the order it was written
            while ( ob_get_level() > $level )
            {
                $out = (string) ob_get_clean() . $out;
            }
        }

        // A CLI dispatch failure is a false and a warning in the log, not an exception: the route
        // does not exist, or the action is not routable
        if ( $routed === false )
        {
            return self::error(self::CODE_NOT_FOUND, 'no such route');
        }

        if ( $routed instanceof reply )
        {
            return $routed->body();
        }

        if ( !self::config('reply_echo') || $out === '' )
        {
            return null;
        }

        return $out;
    }

    /**
     * Clear what the previous message left in the framework's static properties.
     *
     * See the class docblock for what each line is for -- every one of them is a leak between two
     * messages of the same worker, not a tidying up.
     *
     * @param connection $conn
     *
     * @return void
     */
    private static function _reset(connection $conn): void
    {
        plato::reset_request();
        plato::$auth = $conn->get(connection::AUTH);
    }

    /**
     * Callables bound to one hook.
     *
     * @param string $hook
     *
     * @return array<int, callable>
     */
    private static function _hooks(string $hook): array
    {
        return self::$_hooks[$hook] ?? [];
    }

    /**
     * Log a failure and let the error hooks see it.
     *
     * @param connection           $conn
     * @param Throwable            $e
     * @param array<string, mixed> $msg
     *
     * @return void
     */
    private static function _report(connection $conn, Throwable $e, array $msg): void
    {
        log::error(sprintf(
            'server %s on %s from %s: %s',
            self::$_route === '' ? 'message' : self::$_route,
            $conn->id(),
            $conn->remote() === '' ? '-' : $conn->remote(),
            $e->getMessage()
        ));

        foreach ( self::_hooks('error') as $hook )
        {
            try
            {
                call_user_func($hook, $conn, $e, $msg);
            }
            catch ( Throwable )
            {
                // An error hook that fails while reporting an error has nowhere left to go
            }
        }
    }

    /**
     * Install the guard that answers the client when an action ends the worker.
     *
     * An explicit exit in application code still runs shutdown functions and takes the worker down.
     * This guard flushes buffered output and records which route ended the process.
     *
     * @return void
     */
    private static function _guard(): void
    {
        if ( self::$_guarded )
        {
            return;
        }

        self::$_guarded = true;

        register_shutdown_function(function ()
        {
            $conn = self::$_current;

            if ( $conn === null )
            {
                return;
            }

            $out = '';

            while ( ob_get_level() > 0 )
            {
                $out = (string) ob_get_clean() . $out;
            }

            log::error(sprintf(
                'server worker ended inside %s on connection %s; an action called exit',
                self::$_route === '' ? 'a message' : self::$_route,
                $conn->id()
            ));

            $out === '' || $conn->send($out);
        });
    }
}
