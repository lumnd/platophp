<?php

/**
 * Tagged cache entries: group keys under one or more tags and drop the whole group at once
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\cache;

/**
 * A set of tags, and the cache operations scoped to it.
 *
 *     cache::tags('user:7')->set('profile', $profile, 600);
 *     cache::tags('user:7')->get('profile');
 *     cache::tags(['user:7', 'posts'])->remember('feed', 300, fn () => build_feed());
 *     cache::tags('user:7')->flush();          // every entry tagged user:7 becomes unreachable
 *
 * **Tags are versions, not indexes.** Every tag owns a token in the cache, and the real key of an
 * entry is built out of the key plus the current token of each of its tags. flush() replaces the
 * tokens, so every key that referred to the old ones can no longer be reached and expires on its
 * own schedule. That is the only design that works across all four drivers: none of file,
 * memcached, redis and memory can answer "list the keys under this tag" without either a SCAN
 * (which redis alone has, and which is O(keyspace)) or a second index that has to be kept
 * consistent with the entries it points at.
 *
 * What that buys and what it costs:
 *
 *   - flush() is one write per tag whatever the number of entries, and it can never leave a
 *     half cleared group behind
 *   - the entries themselves are **not** deleted. They occupy the store until their own lifetime
 *     runs out, so tagged entries want a lifetime -- `0` (forever) plus flush() leaks
 *   - reading a tagged key costs one extra read per tag, memoized for the rest of the request
 *
 * An entry with several tags is invalidated by whichever of them is flushed first, which is what
 * "tagged with both" is normally taken to mean.
 */
class tags
{
    /**
     * Prefix of the token keys, kept apart from ordinary entries
     */
    private const TOKEN_PREFIX = 'tag_token|';

    /**
     * Tags of this set, in the order they were given
     *
     * @var array<int, string>
     */
    private $_tags;

    private repository $_repository;

    /**
     * @param repository        $repository
     * @param array<int, string> $tags
     */
    public function __construct(repository $repository, array $tags)
    {
        // Sorted, so tags('a', 'b') and tags('b', 'a') address the same entry
        $tags = array_values(array_unique(array_map('strval', $tags)));
        sort($tags);

        $this->_repository = $repository;
        $this->_tags       = $tags;
    }

    /**
     * Write a tagged value.
     *
     * @param string   $key       Key
     * @param mixed    $value     Value
     * @param int|null $cachetime Lifetime in seconds; see the class note on 0
     *
     * @return bool
     */
    public function set($key, $value, $cachetime = null)
    {
        return $this->_repository->set($this->key($key), $value, $cachetime);
    }

    /**
     * Read a tagged value.
     *
     * @param string $key     Key
     * @param mixed  $default Returned when the key is missing, or when one of its tags has been
     *                        flushed
     *
     * @return mixed
     */
    public function get($key, $default = false)
    {
        return $this->_repository->get($this->key($key), $default);
    }

    /**
     * Delete one tagged value.
     *
     * @param string $key Key
     *
     * @return int
     */
    public function del($key)
    {
        return $this->_repository->del($this->key($key));
    }

    /**
     * Whether a tagged key is present and none of its tags has been flushed since.
     *
     * @param string $key Key
     *
     * @return bool
     */
    public function has($key)
    {
        return $this->_repository->has($this->key($key));
    }

    /**
     * cache::remember(), scoped to these tags.
     *
     * @param string   $key       Key
     * @param int|null $cachetime Lifetime in seconds
     * @param callable $producer  Called on a miss
     *
     * @return mixed
     */
    public function remember($key, $cachetime, callable $producer)
    {
        return $this->_repository->remember($this->key($key), $cachetime, $producer);
    }

    /**
     * Invalidate every entry carrying any of these tags.
     *
     * @return bool  False when the cache is disabled or a token could not be written
     */
    public function flush()
    {
        $ok = true;

        foreach ( $this->_tags as $tag )
        {
            // A fresh token rather than a counter: two processes flushing at the same time must
            // not land on the same value and leave the group readable again
            $ok = $this->_repository->set(self::TOKEN_PREFIX . $tag, self::_mint(), 0) && $ok;
        }

        return $ok;
    }

    /**
     * The real cache key of a tagged key.
     *
     * Public because it is also the answer to "what did you actually store this under", which a
     * caller mixing tagged and untagged access needs.
     *
     * @param string $key Key
     *
     * @return string
     */
    public function key($key)
    {
        $parts = [];

        foreach ( $this->_tags as $tag )
        {
            $parts[] = $tag . '=' . $this->_token($tag);
        }

        return 'tags|' . implode('|', $parts) . '|' . $key;
    }

    /**
     * Current token of a tag, minted and stored on first use.
     *
     * @param string $tag
     *
     * @return string
     */
    private function _token($tag)
    {
        $token = $this->_repository->get(self::TOKEN_PREFIX . $tag);

        if ( !is_string($token) || $token === '' )
        {
            $token = self::_mint();
            $this->_repository->set(self::TOKEN_PREFIX . $tag, $token, 0);
        }

        return $token;
    }

    /**
     * A token no other process will produce.
     *
     * @return string
     */
    private static function _mint()
    {
        return bin2hex(random_bytes(8));
    }
}
