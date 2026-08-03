<?php
/**
 * plato\lock: the settings, the connection seam and the token bookkeeping.
 *
 * Driven against a fake connection handed to lock::bind(), so nothing here needs redis. The fake
 * carries the promise unlock() rests on -- EXEC answers false when somebody wrote the key after
 * the WATCH -- because that branch cannot be reached from one process against a real server
 * without a second process to race against.
 */

use plato\cache\redis;
use plato\lock;
use plato\plato;

plato::registry(plato_test_config());

/**
 * The handful of commands lock uses, over an array, with a clock this file moves by hand.
 */
class lock_test_redis extends redis
{
    /**
     * Entries, keyed by the key lock asked for
     *
     * @var array<string, array{value: string, expire: int}>
     */
    public $store = [];

    /**
     * Key and lifetime of every set_nx call, in order
     *
     * @var array<int, array{0: string, 1: int}>
     */
    public $writes = [];

    /**
     * Called once after the next get(), to write the key from inside lock's transaction window
     *
     * @var callable|null
     */
    public $after_get = null;

    /**
     * set_nx attempt on which the key held by somebody else is dropped, null to keep it
     *
     * @var int|null
     */
    public $release_after = null;

    /**
     * Number of writes per key, which is what WATCH compares
     *
     * @var array<string, int>
     */
    private $_versions = [];

    /**
     * Versions snapshotted by watch(), null when nothing is watched
     *
     * @var array<string, int>|null
     */
    private $_watched = null;

    /**
     * Seconds the clock has been moved forward by advance()
     *
     * @var int
     */
    private $_offset = 0;

    public function __construct()
    {
        parent::__construct('lock-test', []);
    }

    /**
     * Move the clock, so a lifetime runs out without the test waiting for it.
     *
     * @param int $seconds
     *
     * @return void
     */
    public function advance(int $seconds): void
    {
        $this->_offset += $seconds;
    }

    /**
     * Write a key behind lock's back, the way another process taking the lock would.
     *
     * @param string $key
     * @param string $value
     * @param int    $expire
     *
     * @return void
     */
    public function steal(string $key, string $value, int $expire = 5): void
    {
        $this->_write($key, $value, $expire);
    }

    /**
     * Lifetime left on a key.
     *
     * @param string $key
     *
     * @return int  -2 when the key is gone, the way redis answers
     */
    public function ttl_left(string $key): int
    {
        return $this->_live($key) ? $this->store[$key]['expire'] - $this->_clock() : -2;
    }

    public function set_nx($key, $value, $expire = 0): bool
    {
        $this->writes[] = [(string) $key, (int) $expire];

        if ( $this->release_after !== null && count($this->writes) >= $this->release_after )
        {
            unset($this->store[$key]);
        }

        if ( $this->_live((string) $key) )
        {
            return false;
        }

        $this->_write((string) $key, (string) $value, (int) $expire);

        return true;
    }

    public function get($key, $default = false)
    {
        $value = $this->_live((string) $key) ? $this->store[$key]['value'] : $default;

        if ( $this->after_get !== null )
        {
            $hook            = $this->after_get;
            $this->after_get = null;

            $hook($this);
        }

        return $value;
    }

    public function has($key): bool
    {
        return $this->_live((string) $key);
    }

    /**
     * The fake stands in for the wrapper and for the handler both: lock reaches WATCH / MULTI /
     * EXEC through client(), and those are declared on this class.
     *
     * @return $this
     */
    public function client()
    {
        return $this;
    }

    public function del($key, ...$more): int
    {
        $deleted = 0;

        foreach ( array_merge([$key], $more) as $one )
        {
            if ( !$this->_live((string) $one) )
            {
                continue;
            }

            unset($this->store[$one]);

            $this->_versions[$one] = ($this->_versions[$one] ?? 0) + 1;
            $deleted++;
        }

        return $deleted;
    }

    public function ttl($key): int
    {
        return $this->ttl_left((string) $key);
    }

    public function expire($key, $expire): bool
    {
        if ( !$this->_live((string) $key) )
        {
            return false;
        }

        $this->_write((string) $key, $this->store[$key]['value'], (int) $expire);

        return true;
    }


    /**
     * @param string ...$keys
     * @return $this
     */
    public function watch(...$keys)
    {
        $this->_watched = [];

        foreach ( $keys as $key )
        {
            $this->_watched[(string) $key] = $this->_versions[(string) $key] ?? 0;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function unwatch()
    {
        $this->_watched = null;

        return $this;
    }

    /**
     * @return $this
     */
    public function discard()
    {
        $this->_watched = null;

        return $this;
    }

    /**
     * @param int $mode
     * @return lock_test_tx
     */
    public function multi($mode = 0)
    {
        return new lock_test_tx($this);
    }

    /**
     * Run what a transaction queued, unless a watched key was written since it was watched.
     *
     * @param array<int, callable> $queued
     *
     * @return array<int, mixed>|false
     */
    public function run_queued(array $queued)
    {
        $watched        = $this->_watched;
        $this->_watched = null;

        foreach ( (array) $watched as $key => $version )
        {
            if ( ($this->_versions[$key] ?? 0) !== $version )
            {
                return false;
            }
        }

        $results = [];

        foreach ( $queued as $command )
        {
            $results[] = $command();
        }

        return $results;
    }

    /**
     * Whether the key is there and has not run out.
     *
     * A key that ran out is dropped on the way, and that is not a write: redis does not abort a
     * WATCH when a key expires on its own, and neither does this.
     *
     * @param string $key
     *
     * @return bool
     */
    private function _live(string $key): bool
    {
        if ( !isset($this->store[$key]) )
        {
            return false;
        }

        if ( $this->store[$key]['expire'] > $this->_clock() )
        {
            return true;
        }

        unset($this->store[$key]);

        return false;
    }

    /**
     * @param string $key
     * @param string $value
     * @param int    $expire
     *
     * @return void
     */
    private function _write(string $key, string $value, int $expire): void
    {
        $this->store[$key]     = ['value' => $value, 'expire' => $this->_clock() + max($expire, 1)];
        $this->_versions[$key] = ($this->_versions[$key] ?? 0) + 1;
    }

    /**
     * @return int
     */
    private function _clock(): int
    {
        return 1000 + $this->_offset;
    }
}

/**
 * What multi() hands back: commands are queued on it and exec() asks the connection to run them.
 */
class lock_test_tx
{
    /**
     * @var lock_test_redis
     */
    private $_redis;

    /**
     * @var array<int, callable>
     */
    private $_queued = [];

    public function __construct(lock_test_redis $redis)
    {
        $this->_redis = $redis;
    }

    /**
     * @param string $key
     * @return $this
     */
    public function del($key)
    {
        $redis = $this->_redis;

        $this->_queued[] = static function () use ($redis, $key)
        {
            return $redis->del($key);
        };

        return $this;
    }

    /**
     * @param string $key
     * @param int    $expire
     * @return $this
     */
    public function expire($key, $expire)
    {
        $redis = $this->_redis;

        $this->_queued[] = static function () use ($redis, $key, $expire)
        {
            return $redis->expire($key, $expire);
        };

        return $this;
    }

    /**
     * @return array<int, mixed>|false
     */
    public function exec()
    {
        return $this->_redis->run_queued($this->_queued);
    }
}

/** @var lock_test_redis|null Connection the current test is bound to */
$fake = null;

beforeEach(function () use (&$fake) {
    lock::reset();

    $fake = new lock_test_redis();

    lock::bind($fake);
});

afterEach(function () {
    // bind(null) first: reset() drops the tokens as well, and nothing should carry over into the
    // files that run after this one, which talk to a real server
    lock::bind(null);
    lock::reset();
});

it('takes the lock on the connection it was handed', function () use (&$fake) {
    expect(lock::lock('a'))->toBeTrue()
        ->and($fake->store)->toHaveKey('Lock:a')
        ->and($fake->writes)->toBe([['Lock:a', 15]]);
});

it('takes the lifetime from the settings when the caller names none', function () use (&$fake) {
    lock::configure(['expire' => 7]);

    lock::lock('a');

    expect($fake->writes[0][1])->toBe(7);
});

it('lets the caller name a lifetime of its own', function () use (&$fake) {
    lock::configure(['expire' => 7]);

    lock::lock('a', 0, 3);

    expect($fake->writes[0][1])->toBe(3);
});

it('clamps a lifetime under one second, so no lock is written without one', function () use (&$fake) {
    lock::lock('a', 0, 0);

    expect($fake->writes[0][1])->toBe(1);
});

it('writes under the configured prefix', function () use (&$fake) {
    lock::configure(['prefix' => 'l:']);

    lock::lock('a');

    expect($fake->store)->toHaveKey('l:a')
        ->and($fake->store)->not->toHaveKey('Lock:a');
});

it('refuses an empty name', function () use (&$fake) {
    expect(lock::lock(''))->toBeFalse()
        ->and($fake->writes)->toBe([]);
});

it('gives up at once on a lock somebody else holds', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::lock('a'))->toBeFalse()
        ->and($fake->writes)->toHaveCount(1)
        ->and($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('keeps trying until the lock is free when it was given a timeout', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);
    // Released on the third attempt, which is the one that has to succeed
    $fake->release_after = 3;

    expect(lock::lock('a', 5, 15, 1))->toBeTrue()
        ->and($fake->writes)->toHaveCount(3);
});

it('reports whether anybody holds the lock', function () use (&$fake) {
    expect(lock::is_locking('a'))->toBeFalse();

    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::is_locking('a'))->toBeTrue();
});

it('deletes the key when it releases the lock', function () use (&$fake) {
    lock::lock('a');

    expect(lock::unlock('a'))->toBeTrue()
        ->and($fake->store)->not->toHaveKey('Lock:a');
});

it('releases nothing it never took', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::unlock('a'))->toBeFalse()
        ->and($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('answers false twice when the same lock is released twice', function () {
    lock::lock('a');

    expect(lock::unlock('a'))->toBeTrue()
        ->and(lock::unlock('a'))->toBeFalse();
});

it('does not release a lock that ran out and was taken over', function () use (&$fake) {
    lock::lock('a', 0, 5);

    $fake->advance(6);
    $fake->steal('Lock:a', 'somebody:else', 30);

    // The token this process holds is not the one on the server any more, so the read catches it
    expect(lock::unlock('a'))->toBeFalse()
        ->and($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('does not release a lock taken over between the read and the delete', function () use (&$fake) {
    lock::lock('a', 0, 5);

    // The one race a single process cannot stage against a real server: the token still reads as
    // ours, and the lock changes hands before the delete is sent
    $fake->after_get = static function (lock_test_redis $redis)
    {
        $redis->steal('Lock:a', 'somebody:else', 30);
    };

    expect(lock::unlock('a'))->toBeFalse()
        ->and($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('extends only a lock it holds', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::expire('a', 60))->toBeFalse()
        ->and($fake->ttl_left('Lock:a'))->toBe(30);
});

it('extends a lock it holds', function () use (&$fake) {
    lock::lock('a', 0, 5);

    expect(lock::expire('a', 60))->toBeTrue()
        ->and($fake->ttl_left('Lock:a'))->toBe(60);
});

it('extends by the configured lifetime when the caller names none', function () use (&$fake) {
    lock::configure(['expire' => 7]);
    lock::lock('a', 0, 5);

    expect(lock::expire('a'))->toBeTrue()
        ->and($fake->ttl_left('Lock:a'))->toBe(7);
});

it('forgets its tokens when the connection is replaced', function () use (&$fake) {
    lock::lock('a');

    $other = new lock_test_redis();
    // The token names a key on the connection being replaced and means nothing on the next one
    lock::bind($other);

    expect(lock::unlock('a'))->toBeFalse()
        ->and($fake->store)->toHaveKey('Lock:a');
});

it('takes the settings back from the file after a reset', function () {
    lock::configure(['prefix' => 'l:', 'expire' => 99]);

    lock::reset();

    expect(lock::config('prefix'))->toBe('Lock:')
        ->and(lock::config('expire'))->toBe(15);
});

it('reads the connection name from the settings', function () {
    expect(lock::config('connection'))->toBe('redis')
        ->and(lock::config('server'))->toBe([]);
});

it('runs the work with the lock held and releases it after', function () use (&$fake) {
    $held = false;

    $answer = lock::guard('a', function () use (&$held) {
        $held = lock::is_locking('a');

        return 'done';
    });

    expect($answer)->toBe('done')
        ->and($held)->toBeTrue()
        ->and($fake->store)->not->toHaveKey('Lock:a');
});

it('releases the lock when the work throws, and lets the throw through', function () use (&$fake) {
    expect(static fn () => lock::guard('a', static function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($fake->store)->not->toHaveKey('Lock:a')
        ->and(lock::owns('a'))->toBeFalse();
});

it('does not run the work when the lock is taken', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);

    $ran = false;

    $answer = lock::guard('a', function () use (&$ran) {
        $ran = true;
    });

    expect($answer)->toBeFalse()
        ->and($ran)->toBeFalse()
        ->and($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('waits for the lock in a guard when it was given a timeout', function () use (&$fake) {
    lock::configure(['wait_interval_us' => 1]);

    $fake->steal('Lock:a', 'somebody:else', 30);
    $fake->release_after = 2;

    expect(lock::guard('a', static fn () => 'done', 5))->toBe('done');
});

it('refuses an empty name in a guard', function () {
    $ran = false;

    $answer = lock::guard('', function () use (&$ran) {
        $ran = true;
    });

    expect($answer)->toBeFalse()
        ->and($ran)->toBeFalse();
});

it('releases through its own token, not through what the job left in the map', function () use (&$fake) {
    // The footgun lock() / unlock() cannot see: a second place in this process releasing the same
    // name by hand, and somebody else taking the lock straight after
    lock::guard('a', function () use (&$fake) {
        lock::unlock('a');
        $fake->steal('Lock:a', 'somebody:else', 30);
    });

    expect($fake->store['Lock:a']['value'])->toBe('somebody:else');
});

it('knows whether this process holds a lock', function () {
    expect(lock::owns('a'))->toBeFalse();

    lock::lock('a');

    expect(lock::owns('a'))->toBeTrue();

    lock::unlock('a');

    expect(lock::owns('a'))->toBeFalse();
});

it('holds the lock for the length of a guard and no longer', function () {
    $inside = false;

    lock::guard('a', function () use (&$inside) {
        $inside = lock::owns('a');
    });

    expect($inside)->toBeTrue()
        ->and(lock::owns('a'))->toBeFalse();
});

it('does not claim a lock another process holds', function () use (&$fake) {
    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::owns('a'))->toBeFalse()
        ->and(lock::is_locking('a'))->toBeTrue();
});

it('reports the lifetime left on a lock', function () use (&$fake) {
    expect(lock::ttl('a'))->toBe(-2);

    lock::lock('a', 0, 30);

    expect(lock::ttl('a'))->toBe(30);

    $fake->advance(10);

    expect(lock::ttl('a'))->toBe(20);
});

it('forgets the token of a lock it turns out to have lost', function () use (&$fake) {
    lock::lock('a', 0, 5);

    $fake->advance(6);
    $fake->steal('Lock:a', 'somebody:else', 30);

    expect(lock::expire('a', 60))->toBeFalse()
        // The token is gone with it: there is nothing left for it to release, and keeping it would
        // have owns() and unlock() answer for a lock this process lost
        ->and(lock::owns('a'))->toBeFalse()
        ->and($fake->ttl_left('Lock:a'))->toBe(30);
});
