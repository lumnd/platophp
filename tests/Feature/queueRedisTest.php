<?php
/**
 * plato\queue\redis against a real server.
 *
 * This needs a reachable Redis. A failure here is an environment problem, not a broken assertion --
 * do not weaken the test to make it pass without the service.
 *
 * Every case works on queues named after this process, and drops the keys afterwards, so two runs
 * on one server cannot read each other's messages.
 */

use plato\cache\redis as client;
use plato\queue\message;
use plato\queue\queue;
use plato\queue\redis;

/**
 * A queue name nothing else is using.
 *
 * @param string $suffix
 * @return string
 */
function queue_test_name(string $suffix = ''): string
{
    return 'platophptest_' . getmypid() . ($suffix === '' ? '' : '_' . $suffix);
}

/**
 * A setting, from the environment of the process or from the .env the fixture app loaded.
 *
 * The QUEUE_ prefixed names come first, exactly as config/queue.php reads them: a developer whose
 * redis is not on the default port needs a way to say so without editing the fixture .env.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function queue_test_env(string $key, $default)
{
    foreach (['QUEUE_' . $key, $key] as $name)
    {
        $value = $_ENV[$name] ?? getenv($name);

        if ($value !== false && $value !== null && $value !== '')
        {
            return $value;
        }
    }

    return $default;
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
function queue_test_client()
{
    return client::instance('queue_probe', [
        'host'       => queue_test_env('REDIS_HOST', '127.0.0.1'),
        'port'       => (int) queue_test_env('REDIS_PORT', 6379),
        'pass'       => queue_test_env('REDIS_PASSWORD', ''),
        'keep-alive' => false,
        'timeout'    => 5,
        'dbindex'    => (int) queue_test_env('REDIS_DB', 4),
        // The driver spells its keys out in full and writes the raw envelope; a prefix or a
        // serializer here would look at something else than what it wrote
        'serializer' => 'none',
        'prefix'     => '',
    ])->client();
}

beforeEach(function () {
    queue::configure([
        'default'     => 'redis',
        'connections' => [
            'redis' => [
                'driver' => 'redis',
                'server' => [
                    'host'       => queue_test_env('REDIS_HOST', '127.0.0.1'),
                    'port'       => (int) queue_test_env('REDIS_PORT', 6379),
                    'pass'       => queue_test_env('REDIS_PASSWORD', ''),
                    'keep-alive' => false,
                    'timeout'    => 5,
                    'dbindex'    => (int) queue_test_env('REDIS_DB', 4),
                    'serializer' => 'none',
                ],
                'prefix' => 'platophptest:queue:',
            ],
        ],
    ]);
});

afterEach(function () {
    $handle = queue_test_client();

    foreach ([queue_test_name(), queue_test_name('other')] as $queue)
    {
        foreach (['', ':delayed', ':failed'] as $suffix)
        {
            $handle->del('platophptest:queue:' . $queue . $suffix);
        }
    }
});

it('pushes and pops in the order it was given', function () {
    $queue = queue_test_name();

    $first  = queue::push($queue, ['n' => 1]);
    $second = queue::push($queue, ['n' => 2]);

    expect($first)->toBeString()->not->toBe('')
        ->and(queue::size($queue))->toBe(2);

    expect(queue::pop($queue, 0)->id())->toBe($first)
        ->and(queue::pop($queue, 0)->id())->toBe($second)
        ->and(queue::pop($queue, 0))->toBeNull();
});

it('writes an envelope another consumer can read', function () {
    $queue = queue_test_name();

    queue::push($queue, ['n' => 1]);

    // Read straight off the list, with no serializer in the way: what a consumer in another
    // language would find there has to be the flat JSON envelope, not a JSON string holding it
    $raw = queue_test_client()->lIndex('platophptest:queue:' . $queue, 0);
    $row = json_decode((string) $raw, true);

    expect($row)->toBeArray()
        ->and($row['queue'])->toBe($queue)
        ->and($row['data'])->toBe(['n' => 1])
        ->and($row['attempts'])->toBe(0);
});

it('blocks for a message and returns null when none arrives', function () {
    $started = microtime(true);

    expect(queue::pop(queue_test_name(), 1000))->toBeNull();

    // BLPOP waited in redis rather than returning at once
    expect(microtime(true) - $started)->toBeGreaterThan(0.9);
});

it('reads the queues it was given in priority order', function () {
    queue::push(queue_test_name('other'), 'second');
    queue::push(queue_test_name(), 'first');

    $order = [
        queue::pop([queue_test_name(), queue_test_name('other')], 0)->payload(),
        queue::pop([queue_test_name(), queue_test_name('other')], 0)->payload(),
    ];

    expect($order)->toBe(['first', 'second']);
});

it('holds a delayed message back and migrates it when it comes due', function () {
    $queue = queue_test_name();

    queue::push($queue, 'later', ['delay' => 60]);

    expect(queue::size($queue))->toBe(0)
        ->and(queue::connection()->pending($queue)['delayed'])->toBe(1);

    // Nothing is due yet, and the caller is told when the next one will be
    [$moved, $next_at] = queue::migrate_delayed($queue);

    expect($moved)->toBe(0)
        ->and($next_at)->toBeGreaterThan(time());

    // Move it back in time rather than waiting a minute for it
    queue_test_client()->zAdd(
        'platophptest:queue:' . $queue . ':delayed',
        time() - 1,
        (string) queue_test_client()->zRange('platophptest:queue:' . $queue . ':delayed', 0, 0)[0]
    );

    [$moved, $next_at] = queue::migrate_delayed($queue);

    expect($moved)->toBe(1)
        ->and($next_at)->toBe(0)
        ->and(queue::size($queue))->toBe(1)
        ->and(queue::pop($queue, 0)->payload())->toBe('later');
});

it('puts a released message back at the tail', function () {
    $queue = queue_test_name();

    queue::push($queue, 'first');
    queue::push($queue, 'second');

    $message = queue::pop($queue, 0);
    queue::release($message->attempted(), 0);

    expect(queue::pop($queue, 0)->payload())->toBe('second')
        ->and(queue::pop($queue, 0)->attempts())->toBe(1);
});

it('moves a failed message to the failed list of its queue', function () {
    $queue = queue_test_name();

    queue::push($queue, 'doomed');

    queue::fail(queue::pop($queue, 0), 'because');

    $pending = queue::connection()->pending($queue);

    expect($pending['failed'])->toBe(1)
        ->and($pending['delayed'])->toBe(0);

    $raw = queue_test_client()->lIndex('platophptest:queue:' . $queue . ':failed', 0);
    $failed = message::decode((string) $raw);

    // The envelope still says which queue it came from, which is what a requeue needs
    expect($failed->queue())->toBe($queue)
        ->and($failed->error())->toBe('because');
});

it('sets aside a list entry that is not an envelope', function () {
    $queue = queue_test_name();

    queue_test_client()->rPush('platophptest:queue:' . $queue, 'not json at all');

    expect(queue::pop($queue, 0))->toBeNull()
        ->and(queue::connection()->pending($queue)['failed'])->toBe(1);
});

it('reports that it can delay', function () {
    expect(queue::can_delay())->toBeTrue()
        ->and(queue::driver())->toBeInstanceOf(redis::class);
});

it('puts failed messages back on their queue', function () {
    $queue = queue_test_name();

    queue::push($queue, ['n' => 1]);
    $message = queue::pop($queue, 0);
    queue::fail($message, 'no');

    expect(queue::connection()->retry_failed($queue))->toBe(1)
        ->and(queue::connection()->pending($queue)['failed'])->toBe(0)
        ->and(queue::size($queue))->toBe(1);

    $again = queue::pop($queue, 0);

    expect($again->attempts())->toBe(0)
        ->and($again->error())->toBeNull()
        ->and($again->id())->toBe($message->id());
});
