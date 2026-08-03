<?php

/**
 * Encrypted request envelope: the route source used when the request path carries no route
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\cache\cache;
use plato\config;
use plato\log;
use plato\plato;
use plato\security\crypt;

/**
 * Encrypted request envelope.
 *
 * Registered with the router as its crypto source: when the request path carries no route of
 * its own, the body is decoded and the routing fields are taken from inside it.
 *
 *     {
 *       "ct":     "article",
 *       "ac":     "edit",
 *       "method": "PATCH",
 *       "ts":     1753776000,
 *       "nonce":  "9f2c...",
 *       "csrf":   "...",
 *       "data":   { "id": 10, "title": "..." }
 *     }
 *
 * What this is and is not:
 *
 *   - it is a wire format and a client identifier
 *   - it is NOT authentication, and it is NOT a csrf defence
 *
 * Client side keys are extractable. A key shipped in browser javascript is public outright, and
 * a key compiled into a mobile binary is recoverable with a little more work, so a valid
 * envelope proves nothing about who produced it. Everything the router does with the decoded
 * ct / ac therefore goes through exactly the same validation as a path, authorisation still runs
 * through check_purview_handle, and csrf is still verified -- see security::csrf_verify(), which
 * reads the token out of the envelope.
 *
 * Per client keys limit the blast radius rather than provide secrecy: a leaked web key does not
 * let anyone forge an ios envelope.
 *
 * The wire codec compresses useful payloads with zlib-wrapped DEFLATE and then authenticates and
 * encrypts them with AES-256-GCM. Requests and responses use different authenticated contexts, so
 * a captured request cannot be reflected as a response or moved under another client name.
 */
class envelope
{
    /**
     * Configuration.
     *
     * clients holds the secrets, so it defaults empty here and is expected to be populated from
     * $_ENV by the application; it is also a list, and framework list defaults cannot be
     * shortened by an application because configuration is merged rather than replaced.
     *
     * @var array<string, mixed>
     */
    protected static $_defaults = [
        // Server key naming the client platform. Only ever selects which key to try: an
        // unknown value is refused rather than falling back to a default key, and the keys are
        // never tried in turn -- trial decryption would turn the failure path into an oracle
        // telling an attacker which platform a captured envelope came from.
        'client_header' => 'HTTP_X_CLIENT',
        // ['web' => '<64 hexadecimal character key>', 'ios' => '...', 'android' => '...']
        'clients'       => [],
        // Accepted clock skew in seconds, and the lifetime of a nonce. 0 disables replay
        // protection entirely, so a captured authenticated envelope remains usable.
        'replay_window' => 300,
        'nonce_prefix'  => 'plato:envelope:nonce',
        // Maximum JSON bytes accepted after decompression.
        'max_plaintext_bytes' => crypt::DEFAULT_MAX_PLAINTEXT_BYTES,
        // Encrypt the response with the same client key, compressing it when that saves bytes.
        'encrypt_reply'  => true,
        'compress_reply' => true,
    ];

    /**
     * Settings, null until config() reads them
     *
     * @var array<string, mixed>|null
     */
    protected static $_config = null;

    /** Whether a valid envelope was decoded for this request */
    protected static $_active = false;

    /** Client platform the envelope was decoded for */
    protected static $_client = '';

    /** Csrf token carried by the envelope */
    protected static $_csrf = '';

    /**
     * Merge settings into the envelope configuration, for tests and long running workers.
     *
     * @param array<string, mixed> $config
     * @return void
     */
    public static function configure(array $config)
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            $cfg = config::instance('config')->get('crypto');
            self::$_config = (is_array($cfg) ? $cfg : []) + self::$_defaults;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Forget the decoded envelope. Long running workers have to call this between requests.
     *
     * @param bool $config Also drop the cached configuration
     * @return void
     */
    public static function reset($config = false)
    {
        self::$_active = false;
        self::$_client = '';
        self::$_csrf   = '';

        if ( $config )
        {
            self::$_config = null;
        }
    }

    /**
     * Whether a valid envelope was decoded for this request.
     *
     * @return bool
     */
    public static function is_active()
    {
        return self::$_active;
    }

    /**
     * Client platform the envelope was decoded for, '' when there was none.
     *
     * @return string
     */
    public static function client()
    {
        return self::$_client;
    }

    /**
     * Csrf token carried by the envelope, '' when there was none.
     *
     * @return string
     */
    public static function csrf()
    {
        return self::$_csrf;
    }

    /**
     * Whether the envelope source is usable at all, ie. at least one client key is configured.
     *
     * @return bool
     */
    public static function is_configured()
    {
        return self::_clients() !== [];
    }

    /**
     * Register this class as the router's crypto source.
     *
     * @return void
     */
    public static function register()
    {
        route::register_crypto_resolver(['plato\http\envelope', 'resolve']);
    }

    /**
     * Decode the request body and report the route it names.
     *
     * Returns null whenever the request is not a valid envelope. The caller turns that into a
     * plain 404: the reason never goes back to the client, because "decrypted but the json was
     * bad" versus "did not decrypt" is exactly the distinction an attacker wants to be told,
     * and malformed JSON is reported as an envelope error.
     *
     * @return array{ct: mixed, ac: mixed, method: mixed}|null
     */
    public static function resolve()
    {
        self::reset();

        if ( req::content_type() !== crypt::MEDIA_TYPE )
        {
            return null;
        }

        $body = req::raw();

        if ( $body === '' )
        {
            return null;
        }

        $client = self::_client_name();

        if ( $client === '' )
        {
            return null;
        }

        $clients = self::_clients();
        $key     = (string) ($clients[$client] ?? '');

        if ( $key === '' )
        {
            return null;
        }

        try
        {
            $json = crypt::decode(
                $body,
                $key,
                self::_context('request', $client),
                (int) self::config('max_plaintext_bytes')
            );
        }
        catch (\Throwable $e)
        {
            log::error('envelope decode failed for client ' . $client . ': ' . $e->getMessage());
            return null;
        }

        $data = json_decode((string) $json, true);

        if ( !is_array($data) )
        {
            log::error('envelope payload is not a json object, client ' . $client);
            return null;
        }

        if ( !self::_fresh($data, $client) )
        {
            return null;
        }

        if ( self::config('encrypt_reply') )
        {
            resp::set_encryption(
                $key,
                self::_context('response', $client),
                (bool) self::config('compress_reply')
            );
        }

        // The payload replaces the request parameters outright rather than merging into them:
        // an envelope request has exactly one source of parameters, and nothing that arrived
        // alongside the ciphertext gets to survive into it
        req::replace_input(isset($data['data']) && is_array($data['data']) ? $data['data'] : []);

        self::$_active = true;
        self::$_client = $client;
        self::$_csrf   = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';

        return [
            'ct'     => $data['ct'] ?? null,
            'ac'     => $data['ac'] ?? null,
            'method' => $data['method'] ?? 'POST',
        ];
    }

    /**
     * Configured client keys.
     *
     * @return array<string, string>
     */
    protected static function _clients()
    {
        $clients = self::config('clients');

        return is_array($clients) ? $clients : [];
    }

    /**
     * Client platform named by the request, '' when absent or not configured.
     *
     * @return string
     */
    protected static function _client_name()
    {
        $header = (string) self::config('client_header');
        $client = strtolower(trim((string) req::server($header, '')));

        if ( $client === '' || preg_match('/^[a-z0-9_-]{1,32}$/', $client) !== 1 )
        {
            return '';
        }

        return array_key_exists($client, self::_clients()) ? $client : '';
    }

    /**
     * Authenticated purpose for one direction of one client channel.
     */
    protected static function _context(string $direction, string $client): string
    {
        return 'plato-envelope:' . $direction . ':' . $client;
    }

    /**
     * Whether the envelope is recent and has not been seen before.
     *
     * Fails closed. Without a working nonce store there is no replay protection at all, so
     * refusing the request is the safer answer than waving it through.
     *
     * The seen check is not atomic: two copies of one envelope arriving at the same moment on
     * different workers can both pass. It is aimed at an envelope captured and sent again
     * later. The GCM nonce prevents key-stream reuse; this application nonce rejects a captured
     * message rather than merely making each encryption unique.
     *
     * @param array<string, mixed> $data
     * @param string $client
     * @return bool
     */
    protected static function _fresh(array $data, $client)
    {
        $window = (int) self::config('replay_window');

        if ( $window <= 0 )
        {
            return true;
        }

        $ts    = isset($data['ts']) ? (int) $data['ts'] : 0;
        $nonce = isset($data['nonce']) && is_string($data['nonce']) ? $data['nonce'] : '';

        if ( $ts <= 0 || $nonce === '' || preg_match('/^[A-Za-z0-9_-]{8,128}$/', $nonce) !== 1 )
        {
            log::error('envelope is missing a usable ts / nonce, client ' . $client);
            return false;
        }

        if ( abs(plato::timestamp() - $ts) > $window )
        {
            log::error('envelope timestamp is outside the replay window, client ' . $client);
            return false;
        }

        $key = self::config('nonce_prefix') . ':' . $client . ':' . $nonce;

        try
        {
            if ( cache::get($key) )
            {
                log::error('envelope nonce has already been used, client ' . $client);
                return false;
            }

            cache::set($key, 1, $window * 2);
        }
        catch (\Throwable $e)
        {
            log::error('envelope nonce store is unavailable, refusing the request: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
