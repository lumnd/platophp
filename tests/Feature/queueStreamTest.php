<?php
/**
 * plato\queue\stream against a real server.
 *
 * This needs a reachable Redis, version 5 or later for streams and 7.0 for the XAUTOCLAIM path --
 * the driver takes over pending entries with XPENDING plus XCLAIM below that, and both paths are
 * exercised here through the same public calls. A failure is an environment problem, not a broken
 * assertion: do not weaken the test to make it pass without the service.
 *
 * Against redis 6.2 these cases are also the guard on the version gate in the driver: 6.2 answers
 * XAUTOCLAIM in a shape phpredis cannot read, and a pop() that reached the command would sit on the
 * socket for a minute rather than fail. Every case here would still pass -- a minute later each.
 *
 * The at-least-once behaviour is what separates this driver from the list one, so most of the cases
 * are about the pending list: a message stays there until it is acknowledged, and comes back when
 * the consumer that took it goes away.
 */

use plato\cache\redis as client;
use plato\queue\queue;
use plato\queue\stream;

/**
 * A queue name nothing else is using.
 *
 * @param string $suffix
 * @return string
 */
function stream_test_name(string $suffix = ''): string
{
    return 'platophpstream_' . getmypid() . ($suffix === '' ? '' : '_' . $suffix);
}

/**
 * A setting, from the environment of the process or from the .env the fixture app loaded.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function stream_test_env(string $key, $default)
{
    foreach ( ['QUEUE_' . $key, $key] as $name )
    {
        $value = $_ENV[$name] ?? getenv($name);

        if ( $value !== false && $value !== null && $value !== '' )
        {
            return $value;
        }
    }

    return $default;
}

/**
 * Point the facade at the stream driver.
 *
 * @param int $claim_idle_ms How long an entry has to be pending before it can be taken over
 */
function stream_test_configure(int $claim_idle_ms = 60000): void
{
    queue::configure([
        'default'     => 'stream',
        'connections' => [
            'stream' => [
                'driver' => 'stream',
                'server' => [
                    'host'       => stream_test_env('REDIS_HOST', '127.0.0.1'),
                    'port'       => (int) stream_test_env('REDIS_PORT', 6379),
                    'pass'       => stream_test_env('REDIS_PASSWORD', ''),
                    'keep-alive' => false,
                    'timeout'    => 5,
                    'dbindex'    => (int) stream_test_env('REDIS_DB', 4),
                    'serializer' => 'none',
                ],
                'prefix'        => 'platophpstream:queue:',
                'group'         => 'test',
                'claim_idle_ms' => $claim_idle_ms,
                'maxlen'        => 0,
            ],
        ],
    ]);
}

/**
 * A phpredis handler on the same server, database and key space as the driver under test.
 *
 * It has to be built from the very settings the driver is configured with: a client taken from the
 * cache configuration instead would sit on another database behind another key prefix and read
 * nothing, which looks exactly like the driver having written nothing.
 *
 * @return \Redis|\RedisCluster
 */
function stream_test_client()
{
    return client::instance('stream_probe', [
        'host'       => stream_test_env('REDIS_HOST', '127.0.0.1'),
        'port'       => (int) stream_test_env('REDIS_PORT', 6379),
        'pass'       => stream_test_env('REDIS_PASSWORD', ''),
        'keep-alive' => false,
        'timeout'    => 5,
        'dbindex'    => (int) stream_test_env('REDIS_DB', 4),
        // The driver spells its keys out in full and writes the raw envelope; a prefix or a
        // serializer here would look at something else than what it wrote
        'serializer' => 'none',
        'prefix'     => '',
    ])->client();
}

beforeEach(function () {
    stream_test_configure();
});

afterEach(function () {
    $handle = stream_test_client();

    foreach ( [stream_test_name(), stream_test_name('other')] as $queue )
    {
        foreach ( ['', ':delayed', ':failed'] as $suffix )
        {
            $handle->del('platophpstream:queue:' . $queue . $suffix);
        }
    }
});

it('pushes and pops in the order it was given', function () {
    $queue = stream_test_name();

    $first  = queue::push($queue, ['n' => 1]);
    $second = queue::push($queue, ['n' => 2]);

    expect($first)->toBeString()->not->toBe('')
        ->and(queue::size($queue))->toBe(2);

    expect(queue::pop($queue, 0)->id())->toBe($first)
        ->and(queue::pop($queue, 0)->id())->toBe($second);
});

it('writes an envelope another consumer can read', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);

    // Straight off the stream: what a consumer in another language finds under the `m` field has
    // to be the flat JSON envelope, not a JSON string holding it
    $entries = stream_test_client()->xRange('platophpstream:queue:' . $queue, '-', '+', 1);
    $fields  = is_array($entries) ? (array) reset($entries) : [];
    $row     = json_decode((string) ($fields['m'] ?? ''), true);

    expect($row)->toBeArray()
        ->and($row['queue'])->toBe($queue)
        ->and($row['data'])->toBe(['n' => 1])
        ->and($row['attempts'])->toBe(0);
});

it('keeps a message pending until it is acknowledged', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);
    $message = queue::pop($queue, 0);

    // Delivered, not gone: this is the whole difference from the list driver
    expect(queue::connection()->pending($queue)['pending'])->toBe(1)
        ->and(queue::size($queue))->toBe(1);

    queue::ack($message);

    expect(queue::connection()->pending($queue)['pending'])->toBe(0)
        // Acknowledged entries are deleted as well, or the stream would grow without bound
        ->and(queue::size($queue))->toBe(0);
});

it('hands a message another consumer abandoned to the next one', function () {
    $queue = stream_test_name();
    $key   = 'platophpstream:queue:' . $queue;

    stream_test_configure(1000);

    queue::push($queue, ['n' => 1]);

    $first = queue::pop($queue, 0);
    expect($first)->not->toBeNull();

    // Give the entry to a consumer that will never come back, and age it past claim_idle_ms in the
    // same command. XCLAIM's IDLE is what makes this deterministic: the entry is 5 seconds idle
    // because redis was told so, not because the test slept and hoped the machine was not busy.
    // It also makes the case mean what its name says -- _consumer() is hostname:pid, so without
    // this the two pops are the same consumer reclaiming its own entry.
    //
    // The id comes from XPENDING rather than from $first->id(): a message id is the envelope's,
    // and what XCLAIM takes is the stream entry's.
    $handle  = stream_test_client();
    $pending = (array) $handle->xPending($key, 'test', '-', '+', 1);

    expect($pending)->toHaveCount(1);

    $handle->xClaim($key, 'test', 'gone-consumer', 0, [(string) $pending[0][0]], ['IDLE' => 5000]);

    // pop() looks for abandoned entries before it reads new ones
    $second = queue::pop($queue, 0);

    expect($second)->not->toBeNull()
        ->and($second->id())->toBe($first->id());

    queue::ack($second);
});

it('does not take over an entry that has not been idle long enough', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);
    $first = queue::pop($queue, 0);

    // claim_idle_ms is the default 60s here, so the entry the first consumer holds is not
    // claimable and there is nothing else to read
    expect(queue::pop($queue, 0))->toBeNull();

    queue::ack($first);
});

it('puts a released message back as a new entry rather than leaving it pending', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);
    $message = queue::pop($queue, 0);

    queue::release($message);

    // Nothing is pending: the old entry was acknowledged and a fresh one written, so the retry
    // happens now rather than after claim_idle_ms
    expect(queue::connection()->pending($queue)['pending'])->toBe(0)
        ->and(queue::size($queue))->toBe(1);

    $again = queue::pop($queue, 0);

    expect($again)->not->toBeNull()
        ->and($again->payload())->toBe(['n' => 1]);
});

it('holds a delayed message back until it is due', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1], ['delay' => 60]);

    // A minute, not a second. push_delay() scores the entry `time() + $delay` and the migrator asks
    // for everything scored at or below `time()`, both at whole-second resolution -- so a one
    // second delay is due the moment the clock ticks over, and "not due yet" was a race the test
    // lost whenever the push landed near the end of a second.
    list($moved, $next_at) = queue::connection()->migrate_delayed($queue);

    expect(queue::size($queue))->toBe(0)
        ->and(queue::connection()->pending($queue)['delayed'])->toBe(1)
        ->and($moved)->toBe(0)
        // The migrator reports when to come back, which is what a worker sleeps on
        ->and($next_at)->toBeGreaterThan(time());
});

it('migrates a delayed message once it comes due', function () {
    $queue   = stream_test_name();
    $delayed = 'platophpstream:queue:' . $queue . ':delayed';

    queue::push($queue, ['n' => 1], ['delay' => 60]);

    // Bring the due time forward rather than wait for it: the score is the unix second the message
    // comes due, so rewriting it puts the set in exactly the state a minute of sleeping would.
    $handle = stream_test_client();
    $member = (array) $handle->zRange($delayed, 0, 0);

    expect($member)->toHaveCount(1);

    $handle->zAdd($delayed, time() - 1, (string) reset($member));

    expect(queue::connection()->migrate_delayed($queue)[0])->toBe(1)
        ->and(queue::size($queue))->toBe(1)
        ->and(queue::connection()->pending($queue)['delayed'])->toBe(0);

    $message = queue::pop($queue, 0);

    expect($message->payload())->toBe(['n' => 1]);
    queue::ack($message);
});

it('moves a failed message to the failed list of its queue', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);
    $message = queue::pop($queue, 0);

    queue::fail($message, 'no');

    expect(queue::connection()->pending($queue)['failed'])->toBe(1)
        // Acknowledged on the way out, so it is not delivered again
        ->and(queue::connection()->pending($queue)['pending'])->toBe(0);

    $raw = stream_test_client()->lIndex('platophpstream:queue:' . $queue . ':failed', 0);
    $row = json_decode((string) $raw, true);

    expect($row['error'])->toBe('no')
        ->and($row['queue'])->toBe($queue);
});

it('sets aside a stream entry that is not an envelope', function () {
    $queue = stream_test_name();
    $key   = 'platophpstream:queue:' . $queue;

    // Create the group first, or the entry written here is not in it
    queue::push($queue, ['n' => 1]);
    queue::ack(queue::pop($queue, 0));

    stream_test_client()->xAdd($key, '*', ['m' => 'not an envelope']);

    expect(queue::pop($queue, 0))->toBeNull()
        ->and(queue::connection()->pending($queue)['failed'])->toBe(1)
        // Acknowledged and deleted, or it would come back on every claim forever
        ->and(queue::connection()->pending($queue)['pending'])->toBe(0);
});

it('reads the queues it was given in priority order', function () {
    queue::push(stream_test_name('other'), 'second');
    queue::push(stream_test_name(), 'first');

    $message = queue::pop([stream_test_name(), stream_test_name('other')], 0);

    expect($message->payload())->toBe('first');
    queue::ack($message);
});

it('blocks for a message and returns null when none arrives', function () {
    // hrtime rather than microtime: the clock this runs against is stepped by its time daemon, and
    // a correction landing inside the second being measured reads back as a wait that never happened
    $started = hrtime(true);

    expect(queue::pop(stream_test_name(), 1000))->toBeNull();

    // The wait happened in redis rather than in usleep()
    expect((hrtime(true) - $started) / 1e9)->toBeGreaterThan(0.9);
});

it('reports that it can delay', function () {
    expect(queue::driver())->toBeInstanceOf(stream::class)
        ->and(is_a(stream::class, plato\queue\delayable::class, true))->toBeTrue();
});

it('puts failed messages back on their queue', function () {
    $queue = stream_test_name();

    queue::push($queue, ['n' => 1]);
    $message = queue::pop($queue, 0);
    queue::fail($message, 'no');

    expect(queue::connection()->retry_failed($queue))->toBe(1)
        ->and(queue::connection()->pending($queue)['failed'])->toBe(0)
        ->and(queue::size($queue))->toBe(1);

    $again = queue::pop($queue, 0);

    // The retry policy starts over, or it would go straight back to the failed list
    expect($again->attempts())->toBe(0)
        ->and($again->error())->toBeNull()
        ->and($again->id())->toBe($message->id());

    queue::ack($again);
});
