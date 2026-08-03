<?php

/**
 * Request signing: one canonical form for a parameter set, and a timing safe check
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

use plato\config;

/**
 * Signs and verifies a parameter set with a shared secret.
 *
 * The point of this class is the canonical form, not the hash. Every project that grew its own
 * `sign()` agreed on roughly "sort the keys, join them, hash with a secret" and then disagreed on
 * every detail that matters: whether nulls are signed, how nested arrays flatten, whether the
 * values and key segments are encoded, which fields are left out. Two implementations that disagree on any one of
 * those produce different signatures for the same request, and the mismatch only shows up against
 * the other side's client. So the rules are written down here once:
 *
 *  1. The signature field itself and everything in `exclude` are dropped.
 *  2. Null values are dropped, so an absent parameter and a null one sign the same.
 *  3. Booleans sign as '1' and '', matching what an HTTP query string carries.
 *  4. Every key segment and value uses RFC 3986 percent encoding.
 *  5. Nested arrays flatten to `key[sub]`; the structural brackets are not encoded.
 *  6. Keys sort as strings, ascending.
 *  7. Pairs join as `key=value` with `&` between.
 *
 * The payload is then run through HMAC. The non-standard `hash(payload . '&key=' . $secret)` shape
 * is available as `style => 'append'` for integrations that require it; HMAC is the default.
 *
 * Secrets are arguments, never configuration: the key table belongs to the application, which
 * reads it from the environment.
 */
class sign
{
    /**
     * Settings the `sign` section does not name.
     *
     * `exclude` defaults to the routing parameters because they address the request rather than
     * describe it -- a route rewritten between client and server would otherwise break the
     * signature. It lives here rather than in config/config.php because list valued defaults in a
     * configuration file cannot be shortened by an application: the two files are merged.
     */
    private const DEFAULTS = [
        'algo'    => 'sha256',
        'field'   => 'sign',
        'style'   => 'hmac',
        'upper'   => false,
        'exclude' => ['ct', 'ac'],
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static $_config = null;

    /**
     * The effective settings, read from the `sign` section on the first call that needs them.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public static function config(?string $key = null)
    {
        if ( self::$_config === null )
        {
            self::$_config = (array) config::instance('config')->get('sign', []) + self::DEFAULTS;
        }

        return $key === null ? self::$_config : (self::$_config[$key] ?? null);
    }

    /**
     * Hand this class its settings instead of letting it read config/config.php.
     *
     * Merges on top of the file settings, so an override names only what it changes. `exclude` is
     * a whole list: naming it replaces the default rather than adding to it.
     *
     * @param array<string, mixed> $config Same shape as the `sign` section
     *
     * @return void
     */
    public static function configure(array $config): void
    {
        self::$_config = $config + (array) self::config();
    }

    /**
     * Drop the overrides, so the next read comes from the file again.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$_config = null;
    }

    /**
     * The canonical string a signature is computed over.
     *
     * Useful on its own when a mismatch has to be debugged: log both sides' payload, and the
     * disagreement is visible without either secret.
     *
     * @param array<string, mixed> $data
     * @param string[]|null        $exclude Field names to leave out, null for the configured list
     *
     * @return string
     */
    public static function payload(array $data, ?array $exclude = null): string
    {
        $drop = $exclude ?? (array) self::config('exclude');
        $drop[] = (string) self::config('field');

        foreach ( $drop as $field )
        {
            unset($data[$field]);
        }

        $pairs = [];
        self::_flatten($data, '', $pairs);
        ksort($pairs, SORT_STRING);

        $out = [];

        foreach ( $pairs as $key => $value )
        {
            $out[] = $key . '=' . rawurlencode($value);
        }

        return implode('&', $out);
    }

    /**
     * Signature of a parameter set.
     *
     * @param array<string, mixed> $data
     * @param string               $secret  Shared secret, from the application's key table
     * @param string[]|null        $exclude Field names to leave out, null for the configured list
     *
     * @return string Hexadecimal digest
     * @throws \InvalidArgumentException When the configured algorithm is not available
     */
    public static function make(array $data, string $secret, ?array $exclude = null): string
    {
        $payload = self::payload($data, $exclude);
        $algo    = (string) self::config('algo');

        $style = (string) self::config('style');

        if ( !in_array($style, ['hmac', 'append'], true) )
        {
            throw new \InvalidArgumentException('Unknown signing style: ' . $style);
        }

        $algorithms = $style === 'hmac' ? hash_hmac_algos() : hash_algos();

        if ( !in_array($algo, $algorithms, true) )
        {
            throw new \InvalidArgumentException('Unsupported ' . $style . ' hash algorithm: ' . $algo);
        }

        $digest = $style === 'append'
            ? hash($algo, $payload . '&key=' . $secret)
            : hash_hmac($algo, $payload, $secret);

        return self::config('upper') ? strtoupper($digest) : $digest;
    }

    /**
     * Whether a parameter set carries a signature that matches.
     *
     * The comparison is hash_equals(), so a caller cannot learn how much of a guess was right by
     * timing the answer. An empty signature fails rather than passing on an empty comparison.
     *
     * @param array<string, mixed> $data
     * @param string               $secret
     * @param string|null          $signature The claimed signature, null to read it out of $data
     * @param string[]|null        $exclude   Field names to leave out, null for the configured list
     *
     * @return bool
     * @throws \InvalidArgumentException When the configured algorithm is not available
     */
    public static function verify(array $data, string $secret, ?string $signature = null, ?array $exclude = null): bool
    {
        if ( $signature === null )
        {
            $claimed   = $data[(string) self::config('field')] ?? null;
            $signature = is_scalar($claimed) ? (string) $claimed : '';
        }

        if ( $signature === '' )
        {
            return false;
        }

        return hash_equals(self::make($data, $secret, $exclude), $signature);
    }

    /**
     * Copy of $data carrying its own signature, ready to send.
     *
     * @param array<string, mixed> $data
     * @param string               $secret
     * @param string[]|null        $exclude Field names to leave out, null for the configured list
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the configured algorithm is not available
     */
    public static function attach(array $data, string $secret, ?array $exclude = null): array
    {
        $data[(string) self::config('field')] = self::make($data, $secret, $exclude);

        return $data;
    }

    /**
     * Walk nested arrays into flat `key[sub]` pairs, dropping nulls along the way.
     *
     * @param array<string, mixed>  $data
     * @param string                $prefix
     * @param array<string, string> $pairs  Collected pairs, by reference
     *
     * @return void
     */
    private static function _flatten(array $data, string $prefix, array &$pairs): void
    {
        foreach ( $data as $key => $value )
        {
            $segment = rawurlencode((string) $key);
            $name    = $prefix === '' ? $segment : $prefix . '[' . $segment . ']';

            if ( $value === null )
            {
                continue;
            }

            if ( is_array($value) )
            {
                self::_flatten($value, $name, $pairs);

                continue;
            }

            if ( is_bool($value) )
            {
                $pairs[$name] = $value ? '1' : '';

                continue;
            }

            // Objects are the caller's business: __toString() or nothing, rather than a silent
            // serialization that the other side has no way to reproduce
            $pairs[$name] = (string) $value;
        }
    }
}
