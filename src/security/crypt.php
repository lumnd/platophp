<?php

/**
 * Authenticated compression and encryption for the PlatoPHP envelope wire format
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\security;

use plato\exception\plato_exception;

/**
 * Versioned DEFLATE plus AES-256-GCM binary messages.
 *
 * The wire format is deliberately small and language neutral:
 *
 *     magic "PLATO" | version 0x01 | flags 0x00/0x01 | nonce 12 | ciphertext n | tag 16
 *
 * Bit 0 of flags says the plaintext was compressed with RFC 1950 zlib-wrapped DEFLATE before
 * encryption. Compression is used only when it makes the payload smaller. The whole header and a
 * caller supplied context are authenticated as additional data, so neither the flags nor the
 * message purpose can be changed without invalidating the tag.
 */
class crypt
{
    public const MAGIC = 'PLATO';

    public const VERSION = 1;

    public const FLAG_DEFLATE = 0x01;

    public const KEY_BYTES = 32;

    public const KEY_HEX_LENGTH = 64;

    public const NONCE_LENGTH = 12;

    public const TAG_LENGTH = 16;

    public const HEADER_LENGTH = 7;

    public const DEFAULT_MAX_PLAINTEXT_BYTES = 4_194_304;

    public const MEDIA_TYPE = 'application/vnd.plato.envelope';

    private const CIPHER = 'aes-256-gcm';

    private const KNOWN_FLAGS = self::FLAG_DEFLATE;

    /**
     * Compress when useful, encrypt, and return the raw wire bytes.
     *
     * @param string $value    Plaintext bytes
     * @param string $key      64 hexadecimal characters encoding a 32 byte key
     * @param string $context  Purpose bound into the authentication tag
     * @param bool   $compress Whether useful DEFLATE compression may be applied
     *
     * @return string Binary wire message
     *
     * @throws plato_exception When an input or required extension is invalid, or encoding fails
     */
    public static function encode(string $value, string $key, string $context, bool $compress = true): string
    {
        self::_openssl_available();

        $raw_key = self::_key($key);
        $context = self::_context($context);
        $payload = $value;
        $flags   = 0;

        if ( $compress )
        {
            self::_zlib_available();

            $compressed = zlib_encode($value, ZLIB_ENCODING_DEFLATE, 9);
            if ( $compressed === false )
            {
                throw new plato_exception('crypt: DEFLATE compression failed');
            }

            if ( strlen($compressed) < strlen($value) )
            {
                $payload = $compressed;
                $flags   = self::FLAG_DEFLATE;
            }
        }

        $header = self::_header($flags);
        $nonce  = random_bytes(self::NONCE_LENGTH);
        $tag    = '';

        $ciphertext = openssl_encrypt(
            $payload,
            self::CIPHER,
            $raw_key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::_aad($header, $context),
            self::TAG_LENGTH
        );

        if ( $ciphertext === false || strlen($tag) !== self::TAG_LENGTH )
        {
            throw new plato_exception('crypt: encryption failed -- ' . self::_openssl_errors());
        }

        return $header . $nonce . $ciphertext . $tag;
    }

    /**
     * Authenticate, decrypt, and decompress a binary wire message.
     *
     * Authentication failures and malformed wire data answer null. They are expected at a public
     * request boundary and deliberately expose no distinction to the caller.
     *
     * @param string $value               Binary wire message produced by encode()
     * @param string $key                 64 hexadecimal characters encoding a 32 byte key
     * @param string $context             The same purpose encode() authenticated
     * @param int    $max_plaintext_bytes Maximum decoded size, protecting the request boundary
     *
     * @return string|null Plaintext bytes, or null when the message is invalid
     *
     * @throws plato_exception When an input or required extension is invalid
     */
    public static function decode(
        string $value,
        string $key,
        string $context,
        int $max_plaintext_bytes = self::DEFAULT_MAX_PLAINTEXT_BYTES
    ): ?string
    {
        self::_openssl_available();

        $raw_key = self::_key($key);
        $context = self::_context($context);

        if ( $max_plaintext_bytes < 1 )
        {
            throw new plato_exception('crypt: maximum plaintext size must be greater than zero');
        }

        $minimum = self::HEADER_LENGTH + self::NONCE_LENGTH + self::TAG_LENGTH;
        if ( strlen($value) < $minimum || strlen($value) > $max_plaintext_bytes + $minimum )
        {
            return null;
        }

        $header = substr($value, 0, self::HEADER_LENGTH);
        if ( !hash_equals(self::MAGIC, substr($header, 0, strlen(self::MAGIC))) )
        {
            return null;
        }

        if ( ord($header[strlen(self::MAGIC)]) !== self::VERSION )
        {
            return null;
        }

        $flags = ord($header[self::HEADER_LENGTH - 1]);
        if ( ($flags & ~self::KNOWN_FLAGS) !== 0 )
        {
            return null;
        }

        $nonce_offset = self::HEADER_LENGTH;
        $body_offset  = $nonce_offset + self::NONCE_LENGTH;
        $tag_offset   = strlen($value) - self::TAG_LENGTH;
        $nonce        = substr($value, $nonce_offset, self::NONCE_LENGTH);
        $ciphertext   = substr($value, $body_offset, $tag_offset - $body_offset);
        $tag          = substr($value, $tag_offset, self::TAG_LENGTH);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $raw_key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::_aad($header, $context)
        );

        if ( $plain === false )
        {
            self::_openssl_errors();
            return null;
        }

        if ( ($flags & self::FLAG_DEFLATE) !== 0 )
        {
            self::_zlib_available();

            $decoded = @zlib_decode($plain, $max_plaintext_bytes);

            return $decoded === false ? null : $decoded;
        }

        return strlen($plain) <= $max_plaintext_bytes ? $plain : null;
    }

    private static function _header(int $flags): string
    {
        return self::MAGIC . chr(self::VERSION) . chr($flags);
    }

    private static function _aad(string $header, string $context): string
    {
        return $header . "\0" . $context;
    }

    /**
     * @throws plato_exception
     */
    private static function _key(string $key): string
    {
        if ( preg_match('/\A[0-9a-fA-F]{' . self::KEY_HEX_LENGTH . '}\z/D', $key) !== 1 )
        {
            throw new plato_exception(
                'crypt: key must be exactly ' . self::KEY_HEX_LENGTH . ' hexadecimal characters'
            );
        }

        $raw = hex2bin($key);

        if ( $raw === false || strlen($raw) !== self::KEY_BYTES )
        {
            throw new plato_exception('crypt: key could not be decoded');
        }

        return $raw;
    }

    /**
     * @throws plato_exception
     */
    private static function _context(string $context): string
    {
        if ( $context === '' )
        {
            throw new plato_exception('crypt: context must not be empty');
        }

        return $context;
    }

    /**
     * @throws plato_exception
     */
    private static function _openssl_available(): void
    {
        if ( !function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') )
        {
            throw new plato_exception('crypt: ext-openssl is required for encrypted envelopes');
        }

        if ( !in_array(self::CIPHER, openssl_get_cipher_methods(), true) )
        {
            throw new plato_exception('crypt: this OpenSSL build does not support ' . self::CIPHER);
        }
    }

    /**
     * @throws plato_exception
     */
    private static function _zlib_available(): void
    {
        if ( !function_exists('zlib_encode') || !function_exists('zlib_decode') )
        {
            throw new plato_exception('crypt: ext-zlib is required for compressed envelopes');
        }
    }

    private static function _openssl_errors(): string
    {
        $errors = [];

        while ( ($error = openssl_error_string()) !== false )
        {
            $errors[] = $error;
        }

        return $errors ? implode('; ', $errors) : 'no openssl error reported';
    }
}
