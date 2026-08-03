<?php

/**
 * Request body parsing: raw bytes plus a content type, in; an array, out
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use plato\exception\request_exception;

/**
 * The four body formats the framework understands, and nothing else.
 *
 * PHP parses exactly one of them for you: a form encoded POST, into `$_POST`. A JSON body, an XML
 * body, a multipart body that did not arrive as POST, and a form encoded PUT / PATCH / DELETE all
 * reach the process as nothing but `php://input`. This class is what turns those into an array.
 *
 * It was lifted out of plato\http\req, which owns the parameter sets, the request metadata (host,
 * ip, method, headers) and the conversion helpers on top of them. Parsing is none of those things:
 * it needs no static state, answers the same way every time for the same three arguments, and is
 * the part of the request layer most worth testing on its own -- a multipart boundary holding a
 * regex metacharacter, a JSON body that is a bare scalar, an XML document with an external entity
 * in it. req still decides *where* the result is stored; this decides only what it is.
 *
 *     $parsed = body::parse($raw, $request->header('Content-Type'), 'put');
 *
 *     $parsed['data']   // the array, or the raw string when nothing understood the type
 *     $parsed['json']   // the same array when the body was JSON, null otherwise
 *     $parsed['xml']    // the same array when the body was XML, null otherwise
 *     $parsed['known']  // false when no branch here claimed the content type
 */
class body
{
    /**
     * Parse a request body.
     *
     * @param string $raw            The bytes, as read from php://input
     * @param string $content_header The whole Content-Type header, parameters included -- the
     *                               multipart branch needs the boundary out of it
     * @param string $method         Lowercased request method
     *
     * @return array{data: array<string, mixed>|string, json: array<string, mixed>|null, xml: array<string, mixed>|null, known: bool}
     *
     * @throws \Exception        When a form encoded body was truncated by max_input_vars
     * @throws \RuntimeException When an XML body is requested without ext-simplexml
     * @throws request_exception When a JSON body is not a JSON object or array
     */
    public static function parse(string $raw, string $content_header, string $method): array
    {
        $type = self::bare_mime($content_header);

        switch ( $type )
        {
            case 'application/x-www-form-urlencoded':
                return self::_form($raw, $method);

            case 'multipart/form-data':
                return self::_multipart($raw, $content_header);

            case 'application/json':
                return self::_json($raw);

            case 'application/xml':
            case 'text/xml':
                return self::_xml($raw);
        }

        // Nothing here knows this content type, so the body is still the raw string. The caller
        // leaves it for the application to read through req::raw()
        return ['data' => $raw, 'json' => null, 'xml' => null, 'known' => $raw === ''];
    }

    /**
     * The media type without its parameters: `application/json; charset=utf-8` is
     * `application/json`.
     *
     * @param string $value A Content-Type header
     *
     * @return string  Lowercased and trimmed
     */
    public static function bare_mime($value): string
    {
        return strtolower(trim(explode(';', (string) $value, 2)[0]));
    }

    /**
     * Flatten a SimpleXMLElement tree into nested arrays.
     *
     * Attributes are dropped, and repeated sibling elements collapse -- the last one of a name
     * wins -- so a document that carries a list does not survive the trip.
     *
     * @param  iterable<string, \SimpleXMLElement> $xmls
     * @return array<string, mixed>
     */
    public static function xml_to_array($xmls): array
    {
        $array = [];

        foreach ( $xmls as $key => $xml )
        {
            $count = $xml->count();

            $array[$key] = $count === 0 ? (string) $xml : self::xml_to_array($xml);
        }

        return $array;
    }

    /**
     * `application/x-www-form-urlencoded`.
     *
     * GET and POST are already in the superglobals, so the body is left as the raw string and only
     * checked for truncation. Every other method has to be parsed here.
     *
     * @param string $raw
     * @param string $method
     *
     * @return array{data: array<string, mixed>|string, json: null, xml: null, known: bool}
     *
     * @throws \Exception When PHP dropped variables past max_input_vars
     */
    private static function _form(string $raw, string $method): array
    {
        if ( $method === 'get' || $method === 'post' )
        {
            // PHP truncates the input silently past max_input_vars often enough that it cannot be
            // relied on to warn -- so count the separators and say so, because a half parsed form
            // is worse than a refused one
            $limit = (int) ini_get('max_input_vars');
            $amps  = substr_count($raw, '&');

            if ( $raw !== '' && $amps > $limit )
            {
                throw new \Exception(
                    'Input truncated by PHP. Number of variables exceeded ' . $limit
                    . '. To increase the limit to at least the ' . $amps . ' needed for this HTTP'
                    . ' request, change the value of "max_input_vars" in php.ini.'
                );
            }

            return ['data' => $raw, 'json' => null, 'xml' => null, 'known' => true];
        }

        $decoded = urldecode($raw);
        parse_str($decoded, $fields);

        return ['data' => $fields, 'json' => null, 'xml' => null, 'known' => true];
    }

    /**
     * `multipart/form-data`, which PHP only populates for a POST.
     *
     * @param string $raw
     * @param string $content_header
     *
     * @return array{data: array<string, mixed>, json: null, xml: null, known: bool}
     */
    private static function _multipart(string $raw, string $content_header): array
    {
        $blocks = [];

        // No boundary, nothing to split on. Without this the pattern below would be built from an
        // empty capture and split the body on a bare '-+'
        if ( preg_match('/boundary=(.*)$/', $content_header, $matches) === 1 )
        {
            // Quoted: the boundary is client supplied and may legally hold '+', '.', '?' and '/',
            // each of which changes what the pattern means -- '/' ends it outright
            $boundary = preg_quote($matches[1], '/');
            $blocks   = (array) preg_split('/-+' . $boundary . '/', $raw);

            // The last element is the closing --boundary-- and holds no field
            array_pop($blocks);
        }

        $fields = [];

        foreach ( $blocks as $block )
        {
            if ( empty($block) )
            {
                continue;
            }

            if ( strpos($block, 'application/octet-stream') !== false )
            {
                // Name, then everything after "stream" bar the leading newlines
                $found = preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
            }
            else
            {
                // Name, then the value between the newline sequences
                $found = preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
            }

            // A block that names no field is not one -- the preamble ahead of the first boundary is
            // one such. preg_match() empties $matches on a miss, so reading $matches[1] regardless
            // produced a parameter under the empty string
            if ( $found !== 1 )
            {
                continue;
            }

            $fields[$matches[1]] = $matches[2] ?? '';
        }

        return ['data' => $fields, 'json' => null, 'xml' => null, 'known' => true];
    }

    /**
     * `application/json`.
     *
     * A body that does not decode, or decodes to a scalar, is refused: a caller that declared JSON
     * and sent something else has made a mistake worth hearing about once.
     *
     * @param string $raw
     *
     * @return array{data: array<string, mixed>, json: array<string, mixed>, xml: null, known: bool}
     *
     * @throws request_exception
     */
    private static function _json(string $raw): array
    {
        try
        {
            $fields = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch ( \JsonException $e )
        {
            throw new request_exception([], 6002, $e);
        }

        if ( !is_array($fields) )
        {
            throw new request_exception([], 6002);
        }

        return ['data' => $fields, 'json' => $fields, 'xml' => null, 'known' => true];
    }

    /**
     * `application/xml` and `text/xml`.
     *
     * A body that does not parse is dropped rather than reported. Nothing on this path may throw:
     * the error handler reads the request back, so an exception here loops -- and logging every
     * malformed document hands an attacker the log file.
     *
     * @param string $raw
     *
     * @return array{data: array<string, mixed>, json: null, xml: array<string, mixed>, known: bool}
     */
    private static function _xml(string $raw): array
    {
        if ( !function_exists('simplexml_load_string') )
        {
            throw new \RuntimeException(
                'plato\http\body requires ext-simplexml to parse XML request bodies.'
            );
        }

        try
        {
            // LIBXML_NONET refuses to fetch anything the document references
            $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET);
            $fields = $xml === false ? [] : self::xml_to_array($xml);
        }
        catch ( \Throwable $e )
        {
            $fields = [];
        }

        return ['data' => $fields, 'json' => null, 'xml' => $fields, 'known' => true];
    }
}
