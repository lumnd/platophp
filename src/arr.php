<?php

/**
 * Array helpers: dot-notated access, recursive merge and row list reshaping
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato;

/**
 * Dot-notated array access.
 *
 * A key like 'database.master.host' walks one level per segment, so nested config can be read
 * and written without checking every level by hand. Plain keys are looked up directly first,
 * which means a literal key containing dots still resolves.
 *
 * The second half of the class reshapes a list of rows -- what a query comes back with:
 * group_by(), pluck(), sort() and tree(). They all address fields through get(), so a dot
 * notated key reaches into a nested row, which is the one thing array_column() and
 * array_multisort() cannot do.
 *
 * Nothing here depends on another part of the framework: this is the layer config, request and
 * language loading are built on, so it must stay reachable without dragging anything else in.
 */
class arr
{
    /**
     * Marks "the key was not found", so a stored null can be told apart from a miss.
     *
     * @var \stdClass|null
     */
    private static $missing;

    /**
     * Read a dot-notated key, returning $default when it does not exist.
     *
     * @param  mixed $array   The array, object or ArrayAccess object to read from
     * @param  mixed $key     Dot-notated key, or an array of keys
     * @param  mixed $default Returned when the key is missing
     *
     * @return mixed
     * @throws \InvalidArgumentException When $array is not something that can be indexed
     */
    public static function get($array, $key = null, $default = null)
    {
        // ArrayAccess is an object, so is_object() already covers it
        if ( ! is_array($array) && ! is_object($array))
        {
            throw new \InvalidArgumentException('First parameter must be an array or object or ArrayAccess object.');
        }

        if (is_null($key))
        {
            return $array;
        }

        if (is_array($key))
        {
            $return = [];
            foreach ($key as $k)
            {
                $return[$k] = static::get($array, $k, $default);
            }
            return $return;
        }

        if (is_object($key))
        {
            $key = (string) $key;
        }

        // A plain object is read through its public properties. ArrayAccess is left alone: it
        // answers offsets rather than properties, so casting it would lose them -- and it must
        // not reach array_key_exists() either, which only takes a real array since PHP 8.
        if (is_object($array) && ! $array instanceof \ArrayAccess)
        {
            $array = (array) $array;
        }

        // Single-segment key: read it straight off, no walk needed
        if (is_array($array) && array_key_exists($key, $array))
        {
            return $array[$key];
        }

        // Multi-segment key: descend one segment at a time
        foreach (explode('.', $key) as $key_part)
        {
            if (is_object($array) && ! $array instanceof \ArrayAccess)
            {
                $array = (array) $array;
            }

            if (($array instanceof \ArrayAccess && isset($array[$key_part])) === false)
            {
                if ( ! is_array($array) || ! array_key_exists($key_part, $array))
                {
                    return $default;
                }
            }

            $array = $array[$key_part];
        }

        return $array;
    }

    /**
     * Write a value at a dot-notated key, creating the intermediate levels.
     *
     * @param  mixed $array The array to write into, by reference
     * @param  mixed $key   Dot-notated key, or an array of key => value pairs
     * @param  mixed $value The value
     *
     * @return void
     */
    public static function set(&$array, $key, $value = null): void
    {
        if (is_null($key))
        {
            $array = $value;
            return;
        }

        if (is_array($key))
        {
            foreach ($key as $k => $v)
            {
                static::set($array, $k, $v);
            }
        }
        else
        {
            $keys = explode('.', $key);

            while (count($keys) > 1)
            {
                $key = array_shift($keys);

                if ( ! isset($array[$key]) || ! is_array($array[$key]))
                {
                    $array[$key] = [];
                }

                $array =& $array[$key];
            }

            $array[array_shift($keys)] = $value;
        }
    }

    /**
     * Unset a dot-notated key.
     *
     * @param  mixed $array The array to remove from, by reference; a level that turns out not
     *                       to be an array simply reports the key as absent
     * @param  mixed $key    Dot-notated key, or an array of keys
     *
     * @return mixed True when removed, false when the key does not exist. An array of keys
     *               gives back an array of those results, keyed the same way
     */
    public static function del(&$array, $key)
    {
        if (is_null($key))
        {
            return false;
        }

        if (is_array($key))
        {
            $return = [];
            foreach ($key as $k)
            {
                $return[$k] = static::del($array, $k);
            }
            return $return;
        }

        $key_parts = explode('.', $key);

        if ( ! is_array($array) || ! array_key_exists($key_parts[0], $array))
        {
            return false;
        }

        $this_key = array_shift($key_parts);

        if ( ! empty($key_parts))
        {
            $key = implode('.', $key_parts);
            return static::del($array[$this_key], $key);
        }
        else
        {
            unset($array[$this_key]);
        }

        return true;
    }

    /**
     * array_key_exists() for a dot-notated key.
     *
     * A key holding null still counts as present, which is why this goes through get() with a
     * sentinel rather than isset().
     *
     * @param  mixed $array The search array
     * @param  mixed $key   Dot-notated key
     *
     * @return bool
     * @throws \InvalidArgumentException When $array is not something that can be indexed
     */
    public static function key_exists($array, $key): bool
    {
        if ( ! is_array($array) && ! $array instanceof \ArrayAccess)
        {
            throw new \InvalidArgumentException('First parameter must be an array or ArrayAccess object.');
        }

        if (is_object($key))
        {
            $key = (string) $key;
        }

        if ( ! is_string($key))
        {
            return false;
        }

        self::$missing ??= new \stdClass();

        return static::get($array, $key, self::$missing) !== self::$missing;
    }

    /**
     * Whether the array is associative, meaning its keys are not 0..n-1 in order.
     *
     * @param  array $arr The array to check
     *
     * @return bool
     */
    public static function is_assoc(array $arr): bool
    {
        // array_is_list() arrived in 8.1 and the package still supports 8.0
        if (function_exists('array_is_list'))
        {
            return ! array_is_list($arr);
        }

        $counter = 0;
        foreach (array_keys($arr) as $key)
        {
            if ( ! is_int($key) || $key !== $counter++)
            {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep only the keys carrying a given prefix.
     *
     * The prefix is matched literally, including regular-expression metacharacters and slashes.
     *
     * @param  array  $array         The array to filter
     * @param  string $prefix        Prefix to filter on
     * @param  bool   $remove_prefix Whether to strip the prefix off the kept keys
     *
     * @return array
     */
    public static function filter_prefixed(array $array, string $prefix, bool $remove_prefix = true): array
    {
        $return = [];
        $length = strlen($prefix);

        foreach ($array as $key => $val)
        {
            if ( ! str_starts_with((string) $key, $prefix))
            {
                continue;
            }

            $return[$remove_prefix === true ? substr((string) $key, $length) : $key] = $val;
        }

        return $return;
    }

    /**
     * Drop every element whose value is in $value, preserving keys.
     *
     * The comparison is deliberately loose: it was written for request input, where everything
     * arrives as a string, matched against values written as literals in an application config
     * file, so filter_value($data, [0]) has to drop the string '0'.
     *
     * @param  array $array
     * @param  mixed $value A single value or a list of values
     *
     * @return array
     */
    public static function filter_value(array $array, $value): array
    {
        $value = (array) $value;

        foreach ($array as $k => $v)
        {
            if ( in_array($v, $value) )
            {
                unset($array[$k]);
            }
        }

        return $array;
    }

    /**
     * Merge arrays recursively. Differs from array_merge_recursive() in two ways:
     * - when two values collide and are not both arrays, the later one overwrites the earlier
     *   instead of both being collected into an array
     * - a numeric key that does not collide keeps its index; only a colliding one is appended
     *
     * @param  array   $array     The array everything else is merged into
     * @param  array[] $arrays    The arrays to merge in, applied left to right
     *
     * @return array
     */
    public static function merge(array $array, array ...$arrays): array
    {
        return self::_merge_into($array, $arrays, true);
    }

    /**
     * Merge arrays recursively, overwriting by index rather than appending.
     *
     * Same as merge() except numeric keys are never renumbered, which is what config overlaying
     * needs: an application file placed over a framework default replaces entry [0] instead of
     * adding a second one.
     *
     * @param  array   $array     The array everything else is merged into
     * @param  array[] $arrays    The arrays to merge in, applied left to right
     *
     * @return array
     */
    public static function merge_assoc(array $array, array ...$arrays): array
    {
        return self::_merge_into($array, $arrays, false);
    }

    /**
     * Group a list of rows by one of their fields.
     *
     * Every group is a list, so two rows sharing a value both survive. Re-keying a list by a
     * field that is unique is a different job and PHP already does it: array_column($rows, null,
     * 'id') keeps one row per key.
     *
     * A row that does not carry the field at all belongs to no group and is dropped -- grouping
     * those under '' would silently merge them with the rows whose value really is an empty
     * string.
     *
     * @param  array           $rows A list of rows
     * @param  string|callable $key  Dot-notated field name, or a callback returning the group
     *
     * @return array<array-key, array<int, mixed>> Group value => the rows carrying it
     */
    public static function group_by(array $rows, $key): array
    {
        self::$missing ??= new \stdClass();

        $groups = [];

        foreach ($rows as $row)
        {
            $group = is_callable($key) ? $key($row) : self::get($row, $key, self::$missing);

            if ($group === self::$missing || $group === null)
            {
                continue;
            }

            $groups[is_bool($group) ? (int) $group : $group][] = $row;
        }

        return $groups;
    }

    /**
     * Read one field out of every row, optionally keyed by another.
     *
     * array_column() with dot notation, and without its requirement that the rows be arrays or
     * plain objects -- this one goes through get(), so ArrayAccess works too.
     *
     * Nothing is deduplicated and nothing is dropped for being empty. Callers that need unique
     * values can use `array_values(array_unique(arr::pluck($rows, 'id')))`.
     *
     * @param  array       $rows  A list of rows
     * @param  string|null $value Dot-notated field to read, null for the whole row
     * @param  string|null $index Dot-notated field to key the result by, null to keep a list
     *
     * @return array
     */
    public static function pluck(array $rows, ?string $value = null, ?string $index = null): array
    {
        self::$missing ??= new \stdClass();

        $return = [];

        foreach ($rows as $row)
        {
            $item = $value === null ? $row : self::get($row, $value, self::$missing);

            // A row that does not carry the field is not part of the column, the way
            // array_column() skips it rather than reporting a null
            if ($item === self::$missing)
            {
                continue;
            }

            $key = $index === null ? self::$missing : self::get($row, $index, self::$missing);

            if ($key === self::$missing || $key === null)
            {
                $return[] = $item;
                continue;
            }

            $return[is_bool($key) ? (int) $key : $key] = $item;
        }

        return $return;
    }

    /**
     * Sort a list of rows by one or more of their fields.
     *
     * Several keys are compared left to right, so ['dept' => 'asc', 'age' => 'desc'] sorts by
     * department and puts the oldest first inside each one. usort() has been stable since PHP
     * 8.0, so rows that compare equal on every key stay in the order they arrived.
     *
     * The result is renumbered, like every other PHP sort: this takes a list and gives a list.
     *
     * @param  array        $rows      A list of rows
     * @param  string|array $key       Dot-notated field, a list of them, or field => direction
     * @param  string       $direction 'asc' or 'desc', the default for keys that name none
     *
     * @return array
     * @throws \InvalidArgumentException When a direction is neither asc nor desc
     */
    public static function sort(array $rows, $key, string $direction = 'asc'): array
    {
        $keys = [];

        foreach ((array) $key as $k => $v)
        {
            // ['age', 'name'] names fields and takes $direction; ['age' => 'desc'] carries its own
            $field = is_int($k) ? $v : $k;
            $order = is_int($k) ? $direction : $v;
            $order = strtolower((string) $order);

            if ($order !== 'asc' && $order !== 'desc')
            {
                throw new \InvalidArgumentException(
                    'Sort direction for "' . $field . '" must be asc or desc, got "' . $order . '".'
                );
            }

            $keys[] = [$field, $order === 'desc' ? -1 : 1];
        }

        usort($rows, static function ($a, $b) use ($keys) {
            foreach ($keys as [$field, $sign])
            {
                $cmp = self::get($a, $field) <=> self::get($b, $field);

                if ($cmp !== 0)
                {
                    return $cmp * $sign;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * Build a nested tree out of a flat id / parent-id list.
     *
     * A row is a root when its parent field equals $root, or when it names a parent that is not
     * in the list at all. Promoting those orphans rather than dropping them means a filtered
     * query -- "every node this user may see" -- still renders, instead of coming back empty
     * because the branch above was filtered out.
     *
     * Rows whose parents form a cycle are the one thing that does get dropped: a cycle is not a
     * tree, and it is better to lose those rows than to recurse forever.
     *
     * @param  array  $rows         A list of rows, each an array
     * @param  string $id_key       Dot-notated field holding the node id
     * @param  string $parent_key   Dot-notated field holding the parent id
     * @param  string $children_key Field the children are written to, added to every node
     * @param  mixed  $root         Parent value marking a top level node
     *
     * @return array
     */
    public static function tree(
        array $rows,
        string $id_key = 'id',
        string $parent_key = 'pid',
        string $children_key = 'children',
        $root = 0
    ): array {
        $by_parent = [];
        $ids       = [];

        foreach ($rows as $row)
        {
            $id = self::get($row, $id_key);

            // Without an id a row can neither be found by its children nor be one itself, and a
            // non scalar id cannot be an array key to bucket anything under
            if ( ! is_array($row) || ! is_scalar($id) )
            {
                continue;
            }

            $ids[$id] = true;
            $parent   = self::get($row, $parent_key);

            $by_parent[is_scalar($parent) ? $parent : ''][] = $row;
        }

        $tree = [];

        foreach ($by_parent as $parent => $bucket)
        {
            // Loose comparison on purpose: a database hands back '0' where the caller wrote 0
            if ( $parent != $root && isset($ids[$parent]) )
            {
                continue;
            }

            foreach ($bucket as $row)
            {
                $tree[] = self::_tree_node($row, $by_parent, $id_key, $children_key, []);
            }
        }

        return $tree;
    }

    /**
     * One node of tree(), with its subtree already attached.
     *
     * $path carries the ids between the root and this node. Two rows are allowed to share an id
     * -- nothing here can stop a query from returning that -- and without the check a row whose
     * parent is its own id would be its own child forever.
     *
     * @param  array               $row
     * @param  array               $by_parent    Rows bucketed by the value of their parent field
     * @param  string              $id_key
     * @param  string              $children_key
     * @param  array<int|string, true> $path     Ids already on the way down, as keys
     *
     * @return array
     */
    private static function _tree_node(
        array $row,
        array $by_parent,
        string $id_key,
        string $children_key,
        array $path
    ): array {
        $id                 = self::get($row, $id_key);
        $row[$children_key] = [];
        $path[$id]          = true;

        foreach ($by_parent[$id] ?? [] as $child)
        {
            $child_id = self::get($child, $id_key);

            if ( isset($path[$child_id]) )
            {
                continue;
            }

            $row[$children_key][] = self::_tree_node($child, $by_parent, $id_key, $children_key, $path);
        }

        return $row;
    }

    /**
     * The body shared by merge() and merge_assoc().
     *
     * @param  array   $array     The array everything else is merged into
     * @param  array[] $arrays    The arrays to merge in, applied left to right
     * @param  bool    $renumber  Whether a colliding numeric key is appended instead of replaced
     *
     * @return array
     */
    private static function _merge_into(array $array, array $arrays, bool $renumber): array
    {
        foreach ($arrays as $arr)
        {
            foreach ($arr as $k => $v)
            {
                if ($renumber && is_int($k))
                {
                    array_key_exists($k, $array) ? $array[] = $v : $array[$k] = $v;
                }
                elseif (is_array($v) && array_key_exists($k, $array) && is_array($array[$k]))
                {
                    $array[$k] = self::_merge_into($array[$k], [$v], $renumber);
                }
                else
                {
                    $array[$k] = $v;
                }
            }
        }

        return $array;
    }
}
