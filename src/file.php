<?php

/**
 * File and path helpers
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

class file
{
    /**
     * Extension of a file name, without the dot.
     *
     * @param  string $filename
     *
     * @return string  The whole name when it carries no dot
     */
    public static function file_ext($filename)
    {
        $arr = explode(".", $filename);
        return end($arr);
    }

    /**
     * Make sure a directory exists, creating it and its parents when it does not.
     *
     * The second existence check covers the race where a concurrent process created the same
     * directory between the check and the mkdir, which makes mkdir fail for a harmless reason.
     *
     * @param  string $path Directory path
     *
     * @return string|bool  The path, or false when it could not be created
     */
    public static function path_exists($path)
    {
        $pathinfo = pathinfo($path . '/tmp.txt');
        if ( !empty($pathinfo ['dirname']) )
        {
            if (file_exists($pathinfo ['dirname']) === false)
            {
                if (@mkdir($pathinfo ['dirname'], 0777, true) === false)
                {
                    if (file_exists($pathinfo ['dirname']))
                    {
                        return $path;
                    }
                    return false;
                }
            }
        }
        return $path;
    }

    /**
     * Write a file, creating the directories leading to it.
     *
     * @param  string $file    Target path
     * @param  string $content
     * @param  int    $flag    FILE_APPEND to append; anything else overwrites under LOCK_EX
     *
     * @return int|false  Bytes written, or false on failure
     */
    public static function put_file($file, $content, $flag = 0)
    {
        $pathinfo = pathinfo($file);
        if (! empty($pathinfo ['dirname']))
        {
            if (file_exists($pathinfo ['dirname']) === false)
            {
                if (@mkdir($pathinfo ['dirname'], 0777, true) === false)
                {
                    return false;
                }
            }
        }
        if ($flag === FILE_APPEND)
        {
            return @file_put_contents($file, $content, FILE_APPEND);
        }
        else
        {
            return @file_put_contents($file, $content, LOCK_EX);
        }
    }

    /**
     * Prefix a relative image path with $_ENV['FILE_LINK'], leaving absolute URLs alone.
     *
     * @param  mixed  $img_url Image path, or a list of them
     * @param  string $suffix  Extension appended when the path carries none; '' to skip
     *
     * @return mixed
     */
    public static function get_img_url($img_url, $suffix = '')
    {
        $filelink = rtrim($_ENV['FILE_LINK'] ?? '', '/');
        if ( is_array($img_url) )
        {
            $img_url = array_map(function ($v) {
                return static::get_img_url($v);
            }, $img_url);
        }
        elseif ($img_url && false != strcasecmp(substr($img_url, 0, 4), 'http') )
        {
            $img_url  = $filelink . '/' . $img_url;
            $img_url .= $suffix && strpos($img_url, '.') === false ? '.' . $suffix : '';
        }

        return $img_url;
    }
}
