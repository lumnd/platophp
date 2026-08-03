<?php

/**
 * Uploaded files: the one place $_FILES is read
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

/**
 * The files that arrived with the request.
 *
 * Split out of plato\http\req, which reads the rest of the request. $_FILES is a different shape
 * from every other input -- a nested array per field, with PHP's own error codes in it -- and it is
 * the only input a controller consumes by moving something rather than by reading a value.
 *
 *     if ( upload::exists('avatar') )
 *     {
 *         upload::extension_is('avatar', ['jpg', 'png'])
 *             && upload::move('avatar', storage::path('avatars/' . $id . '.jpg'));
 *     }
 *
 * **An array field takes an index.** `<input name="photos[]">` arrives as one entry whose every
 * value is itself an array, so each method takes a trailing `$item`:
 *
 *     foreach ( array_keys((array) upload::info('photos', null)['name'] ?? []) as $i )
 *     {
 *         upload::move('photos', $dir . '/' . $i . '.jpg', $i);
 *     }
 *
 * **What the client says is not what the file is.** extension() reads the content type and the file
 * name out of the request, so it reports what the upload *claims*. Anything deciding whether a file
 * is safe to keep has to look at the bytes -- finfo, getimagesize -- and an upload directory the web
 * server will execute is a hole that no extension list closes.
 *
 * req::capture() hands $_FILES over and empties it, and req::reset_input() clears this as well, so
 * the request boundary is the same one: a resident entry point that resets req resets this too.
 */
class upload
{
    /**
     * $_FILES, as handed over by capture()
     *
     * @var array<array-key, mixed>
     */
    public static $files = array();

    /**
     * Destination names move() refuses, whatever the field was called.
     *
     * The last line of defence and not the first one, see the class note above.
     *
     * @var string
     */
    public static $filter_filename = '/\.(php|pl|sh|js)$/i';

    /**
     * Take the uploads over from $_FILES, which is then unset so nothing else reads it.
     *
     * @param array<array-key, mixed> $files
     * @return void
     */
    public static function capture(array &$files)
    {
        self::$files = $files;
        unset($_FILES);
    }

    /**
     * Forget this request's uploads.
     *
     * A resident entry point serves many requests per process; without this the previous request's
     * uploads are still readable, and their temporary files are already gone.
     *
     * @return void
     */
    public static function reset()
    {
        self::$files = array();
    }

    /**
     * Put a value in the upload set directly.
     *
     * For input that carries a file without being a multipart upload -- req::_hydrate() moves
     * base64 encoded fields here, so that a whole encoded file does not sit in the parameter sets
     * and get copied into every log line that dumps them.
     *
     * @param string $field
     * @param mixed  $value
     * @return void
     */
    public static function set($field, $value)
    {
        self::$files[$field] = $value;
    }

    /**
     * Every upload, as $_FILES was shaped.
     *
     * @return array<array-key, mixed>
     */
    public static function all()
    {
        return self::$files;
    }

    /**
     * Whether a file arrived under this name and PHP accepted it.
     *
     * An upload over upload_max_filesize is present in $_FILES *with an error code*, not absent,
     * which is why this tests the code rather than the key.
     *
     * @param string $field
     * @param string $item  Index within an array field
     * @return bool
     */
    public static function exists($field, $item = '')
    {
        if ( $item === '' )
        {
            return isset(self::$files[$field]['error'])
                && self::$files[$field]['error'] == UPLOAD_ERR_OK;
        }

        return isset(self::$files[$field]['error'][$item])
            && self::$files[$field]['error'][$item] == UPLOAD_ERR_OK;
    }

    /**
     * The $_FILES entry for a field.
     *
     * @param string $field
     * @param string $item
     * @return mixed  False when there is nothing under that name
     */
    public static function info($field, $item = '')
    {
        if ( !isset(self::$files[$field]) )
        {
            return false;
        }

        if ( $item === '' )
        {
            return self::$files[$field];
        }

        return isset(self::$files[$field][$item]) ? self::$files[$field][$item] : false;
    }

    /**
     * The temporary path PHP parked an upload at.
     *
     * @param string $field
     * @param string $default
     * @param string $item
     * @return mixed
     */
    public static function tmp_name($field, $default = '', $item = '')
    {
        if ( $item === '' )
        {
            return self::$files[$field]['tmp_name'] ?? $default;
        }

        return self::$files[$field]['tmp_name'][$item] ?? $default;
    }

    /**
     * The extension an upload should be saved with.
     *
     * Taken from the content type where that names an image, and from the file name otherwise.
     * Both come from the request -- see the class note.
     *
     * @param string $field
     * @param string $item
     * @return string  '' when neither the type nor the name says anything
     */
    public static function extension($field, $item = '')
    {
        $types = [
            'image/jpeg'  => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/gif'   => 'gif',
            'image/png'   => 'png',
            'image/xpng'  => 'png',
            'image/wbmp'  => 'bmp',
        ];

        $type = $item === ''
            ? (self::$files[$field]['type'] ?? '')
            : (self::$files[$field]['type'][$item] ?? '');

        $type = strtolower((string) $type);

        if ( isset($types[$type]) )
        {
            return $types[$type];
        }

        $name = $item === ''
            ? (self::$files[$field]['name'] ?? '')
            : (self::$files[$field]['name'][$item] ?? '');

        $name = (string) $name;

        if ( strpos($name, '.') === false )
        {
            return '';
        }

        $parts = explode('.', $name);

        return strtolower((string) end($parts));
    }

    /**
     * Whether an upload's extension is one of the accepted ones.
     *
     * @param string             $field
     * @param array<int, string> $allowed
     * @param string             $item
     * @return bool
     */
    public static function extension_is($field, $allowed = array('csv'), $item = '')
    {
        return in_array(self::extension($field, $item), (array) $allowed, true);
    }

    /**
     * Move an upload into place.
     *
     * @param string $field
     * @param string $path  Destination; one matching self::$filter_filename is refused
     * @param string $item
     *
     * @return bool  False when there was no upload under that name, when the destination is a name
     *               this refuses, or when the move itself failed
     */
    public static function move($field, $path, $item = '')
    {
        if ( !self::exists($field, $item) )
        {
            return false;
        }

        if ( preg_match(self::$filter_filename, $path) )
        {
            return false;
        }

        $tmp = self::tmp_name($field, '', $item);

        if ( !is_string($tmp) || $tmp === '' )
        {
            return false;
        }

        // move_uploaded_file() and not rename() or copy(): it checks that the source really is
        // this request's upload, which is what stops a crafted tmp_name from moving an arbitrary
        // file the process can read
        return move_uploaded_file($tmp, $path);
    }
}
