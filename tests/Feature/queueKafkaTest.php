<?php
/**
 * plato\queue\kafka against a real broker.
 *
 * This needs a reachable kafka and ext-rdkafka. A failure is an environment problem, not a broken
 * assertion: do not weaken the test to make it pass without the service. tests/Unit/queue/kafkaTest
 * covers everything that can be decided without connecting -- the contracts, the dead letter topic
 * name, the refusals -- so what is here is only what a broker can answer.
 *
 * The driver's docblock makes four claims that nothing but a broker can check, and they are what
 * these cases are about: the envelope on the wire is readable by a consumer that is not this
 * package, ack() commits an offset rather than deleting anything, release() re-produces because an
 * offset cannot be un-committed for one message alone, and a committed topic can still be replayed
 * from the start by another group.
 *
 * **Every case works on its own topic and its own consumer group**, named after the process id, so
 * two runs never read each other's messages. Nothing deletes them afterwards: php-rdkafka 6.0.5
 * has no admin API, and the alternative -- shelling into the broker container -- would tie the
 * suite to one deployment. The topics are empty and a few kilobytes each; on the dnmp broker they
 * can be swept up with
 *
 *     docker exec kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 \
 *         --delete --topic 'platophpkafka_.*'
 *
 * A fresh consumer group has to join before it is given any partition, which takes a moment even
 * with `group.initial.rebalance.delay.ms` at 0. That is why nothing here calls pop() once and
 * concludes the queue is empty: kafka_test_pop() polls until a deadline, and only a deadline that
 * expires means there was nothing to read.
 */

use plato\exception\queue_exception;
use plato\queue\kafka;
use plato\queue\message;
use plato\queue\queue;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;

/**
 * How long a poll waits for a message that should be there, in milliseconds.
 *
 * It has to cover a group join, not a round trip: the broker answers in single digit
 * milliseconds, joining a group takes the best part of a second.
 */
const KAFKA_TEST_WAIT_MS = 20000;

/**
 * A setting, from the environment of the process or from the .env the fixture app loaded.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function kafka_test_env(string $key, $default)
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
 * The brokers under test.
 *
 * @return string
 */
function kafka_test_brokers(): string
{
    return (string) kafka_test_env('KAFKA_BROKERS', 'kafka:9092');
}

/**
 * A topic nothing else is using.
 *
 * @param string $suffix
 * @return string
 */
function kafka_test_topic(string $suffix = ''): string
{
    return 'platophpkafka_' . getmypid() . ($suffix === '' ? '' : '_' . $suffix);
}

/**
 * A consumer group nothing else is using.
 *
 * Distinct per case: a group that has already committed the offsets of a previous case would
 * start where that one left off, and the case would look like it had lost its messages.
 *
 * @param string $suffix
 * @return string
 */
function kafka_test_group(string $suffix = ''): string
{
    return 'platophpkafka_' . getmypid() . ($suffix === '' ? '' : '_' . $suffix);
}

/**
 * Point the facade at the kafka driver.
 *
 * `connections` replaces the whole block from config/queue.php, so everything the driver reads is
 * spelled out here -- including `auto.offset.reset`, without which a new group would skip
 * everything produced before it joined and every case would time out.
 *
 * @param string               $group     Consumer group
 * @param array<string, mixed> $overrides Connection settings to change
 * @return void
 */
function kafka_test_configure(string $group, array $overrides = []): void
{
    queue::configure([
        'default'     => 'kafka',
        'connections' => [
            'kafka' => $overrides + [
                'driver'   => 'kafka',
                'brokers'  => kafka_test_brokers(),
                'group_id' => $group,
                // Synchronous: a push() that returned an id means the broker has the message, which
                // is the only mode in which the assertions below are about kafka rather than about
                // a local buffer
                'flush_mode'        => 'sync',
                'flush_timeout_ms'  => 10000,
                'dead_letter_topic' => '%s.dlq',
                'conf'              => [
                    'security.protocol' => 'PLAINTEXT',
                ],
                'consumer_conf' => [
                    'enable.auto.commit' => 'false',
                    'auto.offset.reset'  => 'earliest',
                ],
                'topic_conf' => [
                    'request.required.acks' => '-1',
                ],
            ],
        ],
    ]);
}

/**
 * Poll until a message arrives or the deadline passes.
 *
 * A single pop() on a consumer that has just subscribed nearly always returns null -- the group is
 * still being assigned its partitions -- so a null from one call says nothing at all. Only an
 * expired deadline means the topic is empty.
 *
 * @param string|array<int, string> $queues
 * @param int                       $wait_ms Total time to keep asking
 * @return message|null
 */
function kafka_test_pop($queues, int $wait_ms = KAFKA_TEST_WAIT_MS)
{
    $deadline = microtime(true) + $wait_ms / 1000;

    do
    {
        $message = queue::pop($queues, 500);

        if ( $message !== null )
        {
            return $message;
        }
    }
    while ( microtime(true) < $deadline );

    return null;
}

/**
 * Read one raw payload off a topic with a consumer this package did not build.
 *
 * The point of the wire format is that something other than plato\queue can read it, so the cases
 * about the envelope and about the dead letter topic go through a plain rdkafka consumer rather
 * than through the driver that wrote it.
 *
 * @param string $topic
 * @param string $group
 * @param int    $wait_ms
 * @return string|null  The payload, null when nothing arrived before the deadline
 */
function kafka_test_raw(string $topic, string $group, int $wait_ms = KAFKA_TEST_WAIT_MS)
{
    $conf = new Conf();
    $conf->set('metadata.broker.list', kafka_test_brokers());
    $conf->set('group.id', $group);
    $conf->set('enable.auto.commit', 'false');
    $conf->set('auto.offset.reset', 'earliest');

    $consumer = new KafkaConsumer($conf);
    $consumer->subscribe([$topic]);

    $deadline = microtime(true) + $wait_ms / 1000;
    $payload  = null;

    do
    {
        $reply = $consumer->consume(500);

        if ( $reply->err === RD_KAFKA_RESP_ERR_NO_ERROR )
        {
            $payload = (string) $reply->payload;
            break;
        }
    }
    while ( microtime(true) < $deadline );

    $consumer->close();

    return $payload;
}

/**
 * Produce a payload the driver did not write, to a topic it is reading.
 *
 * @param string $topic
 * @param string $payload
 * @return bool  Whether the broker confirmed it
 */
function kafka_test_produce_raw(string $topic, string $payload): bool
{
    $conf = new Conf();
    $conf->set('metadata.broker.list', kafka_test_brokers());

    $producer = new Producer($conf);
    $producer->newTopic($topic)->produce(RD_KAFKA_PARTITION_UA, 0, $payload);

    return $producer->flush(10000) === RD_KAFKA_RESP_ERR_NO_ERROR;
}

beforeEach(function () {
    if ( !extension_loaded('rdkafka') )
    {
        $this->markTestSkipped('the kafka cases need ext-rdkafka');
    }
});

afterEach(function () {
    // Closes the consumer, which leaves the group rather than letting the broker wait out the
    // session timeout before the next case can be assigned the same partitions
    queue::reset();
});

it('produces a message a consumer of its own reads back', function () {
    $topic = kafka_test_topic('roundtrip');
    kafka_test_configure(kafka_test_group('roundtrip'));

    $id = queue::push($topic, ['n' => 1]);

    // sync flush: an id means the broker acknowledged it, not that it sits in a local buffer
    expect($id)->toBeString()->not->toBe('');

    $message = kafka_test_pop($topic);

    expect($message)->not->toBeNull()
        ->and($message->id())->toBe($id)
        ->and($message->queue())->toBe($topic)
        ->and($message->payload())->toBe(['n' => 1])
        ->and($message->attempts())->toBe(0);

    expect(queue::ack($message))->toBeTrue();
});

it('writes the flat envelope a consumer in another language can read', function () {
    $topic = kafka_test_topic('wire');
    kafka_test_configure(kafka_test_group('wire'));

    $id = queue::push($topic, ['n' => 1]);

    // Straight off the topic, through a consumer this package did not build: the payload has to be
    // the envelope itself, not a JSON string holding it
    $row = json_decode((string) kafka_test_raw($topic, kafka_test_group('wire_raw')), true);

    expect($row)->toBeArray()
        ->and($row['id'])->toBe($id)
        ->and($row['queue'])->toBe($topic)
        ->and($row['data'])->toBe(['n' => 1])
        ->and($row['attempts'])->toBe(0);
});

it('commits the offset on ack so the group is not given the message again', function () {
    $topic = kafka_test_topic('ack');
    $group = kafka_test_group('ack');
    kafka_test_configure($group);

    queue::push($topic, ['n' => 1]);

    $message = kafka_test_pop($topic);
    expect($message)->not->toBeNull();
    expect(queue::ack($message))->toBeTrue();

    // A new consumer in the same group starts from the committed offset, which is past this
    // message. Nothing was deleted -- the record is still on the broker, see the next case
    queue::reset();
    kafka_test_configure($group);

    expect(kafka_test_pop($topic, 8000))->toBeNull();
});

it('replays a committed topic for a group that has not read it', function () {
    $topic = kafka_test_topic('replay');
    kafka_test_configure(kafka_test_group('replay_first'));

    $id = queue::push($topic, ['n' => 1]);

    $first = kafka_test_pop($topic);
    expect($first)->not->toBeNull();
    queue::ack($first);

    // The offset of the first group says nothing about the second: a topic is a log, and this is
    // what separates it from the two redis drivers, where a popped message is off the queue
    queue::reset();
    kafka_test_configure(kafka_test_group('replay_second'));

    $second = kafka_test_pop($topic);

    expect($second)->not->toBeNull()
        ->and($second->id())->toBe($id);

    queue::ack($second);
});

it('puts a released message back by producing a copy of it', function () {
    $topic = kafka_test_topic('release');
    kafka_test_configure(kafka_test_group('release'), ['flush_mode' => 'async']);

    $id = queue::push($topic, ['n' => 1]);

    $message = kafka_test_pop($topic);
    expect($message)->not->toBeNull();

    // Not by withholding the commit: an offset commits everything before it, so the copy is the
    // only way to replay one message without replaying the partition behind it. Even though normal
    // pushes are async here, release waits for the broker before it commits the original offset
    expect(queue::release($message))->toBeTrue();

    $again = kafka_test_pop($topic);

    expect($again)->not->toBeNull()
        ->and($again->id())->toBe($id)
        ->and($again->payload())->toBe(['n' => 1]);

    queue::ack($again);
});

it('moves a failed message to the dead letter topic with the error on it', function () {
    $topic = kafka_test_topic('fail');
    kafka_test_configure(kafka_test_group('fail'), ['flush_mode' => 'async']);

    queue::push($topic, ['n' => 1]);

    $message = kafka_test_pop($topic);
    expect($message)->not->toBeNull();

    expect(queue::fail($message, 'no'))->toBeTrue();

    $row = json_decode((string) kafka_test_raw($topic . '.dlq', kafka_test_group('fail_dlq')), true);

    expect($row)->toBeArray()
        ->and($row['error'])->toBe('no')
        // Still naming the topic it came from, so whoever reads the dead letter topic knows where
        // to put it back
        ->and($row['queue'])->toBe($topic);

    // Committed on the way out, so the consumer is not handed it a second time
    expect(kafka_test_pop($topic, 8000))->toBeNull();
});

it('sets aside a record that is not an envelope instead of stopping on it', function () {
    $topic = kafka_test_topic('foreign');
    kafka_test_configure(kafka_test_group('foreign'));

    // Something else produces into this topic. Refusing to move past it would hold up everything
    // behind it forever
    expect(kafka_test_produce_raw($topic, 'not an envelope'))->toBeTrue();

    expect(kafka_test_pop($topic, 8000))->toBeNull();

    $aside = kafka_test_raw($topic . '.dlq', kafka_test_group('foreign_dlq'));

    expect($aside)->toBe('not an envelope');
});

it('keeps messages that share a key on one partition', function () {
    $topic = kafka_test_topic('key');
    kafka_test_configure(kafka_test_group('key'));

    queue::push($topic, ['n' => 1], ['key' => 'tenant-7']);
    queue::push($topic, ['n' => 2], ['key' => 'tenant-7']);

    $first  = kafka_test_pop($topic);
    $second = kafka_test_pop($topic);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull();

    // Ordering is per partition, so a payload that has to stay behind another one is a key, not a
    // queue setting. The topic has three partitions; without the key these two would be spread
    expect($first->handle()->partition)->toBe($second->handle()->partition)
        // And on one partition the order is the order they were produced in
        ->and([$first->payload(), $second->payload()])->toBe([['n' => 1], ['n' => 2]]);

    queue::ack($second);
});

it('reports the lag of its group as the queue size', function () {
    $topic = kafka_test_topic('lag');
    kafka_test_configure(kafka_test_group('lag'));

    queue::push($topic, ['n' => 1]);
    queue::push($topic, ['n' => 2]);

    // A group that has committed nothing is behind by everything on the topic
    expect(queue::size($topic))->toBe(2);

    queue::ack(kafka_test_pop($topic));
    queue::ack(kafka_test_pop($topic));

    expect(queue::size($topic))->toBe(0);
});

it('reads from several topics in one subscription', function () {
    $first  = kafka_test_topic('multi_a');
    $second = kafka_test_topic('multi_b');
    kafka_test_configure(kafka_test_group('multi'));

    queue::push($first, 'a');
    queue::push($second, 'b');

    $seen = [];

    // No priority order to assert: kafka hands out whatever partition has something, and the
    // driver takes what it is given. Both have to arrive, that is all
    for ( $i = 0; $i < 2; $i++ )
    {
        $message = kafka_test_pop([$first, $second]);

        expect($message)->not->toBeNull();

        $seen[] = $message->payload();
        queue::ack($message);
    }

    sort($seen);

    expect($seen)->toBe(['a', 'b']);
});

it('treats a topic nobody has produced to as nothing to read', function () {
    $topic = kafka_test_topic('missing');
    kafka_test_configure(kafka_test_group('missing'));

    // A consumer started before its producer subscribes to a topic that does not exist yet, and
    // the broker answers `Unknown topic or partition` on the same channel it uses for real
    // failures. Raising it would kill a worker that was merely early
    expect(kafka_test_pop($topic, 5000))->toBeNull();

    // And once something is produced, the same consumer picks it up without being restarted
    $id = queue::push($topic, ['n' => 1]);

    $message = kafka_test_pop($topic);

    expect($message)->not->toBeNull()
        ->and($message->id())->toBe($id);

    queue::ack($message);
});

it('waits in the broker for a message and returns null when none arrives', function () {
    $topic = kafka_test_topic('idle');
    kafka_test_configure(kafka_test_group('idle'));

    // Join the group first, or what is timed below is the rebalance rather than the read
    kafka_test_pop($topic, 5000);

    $started = microtime(true);

    expect(queue::pop($topic, 1000))->toBeNull();

    expect(microtime(true) - $started)->toBeGreaterThan(0.9);
});

it('returns null from a synchronous push the broker never acknowledged', function () {
    $topic = kafka_test_topic('unreachable');

    // Nothing is listening on this port. In sync mode that has to be the difference between a
    // message the broker has and a message that was only ever in a local buffer
    kafka_test_configure(kafka_test_group('unreachable'), [
        'brokers'          => '127.0.0.1:1',
        'flush_timeout_ms' => 2000,
    ]);

    expect(queue::push($topic, ['n' => 1]))->toBeNull();
});

it('is the kafka driver and still refuses a delay against a real broker', function () {
    kafka_test_configure(kafka_test_group('delay'));

    expect(queue::driver())->toBeInstanceOf(kafka::class)
        ->and(queue::can_delay())->toBeFalse();

    queue::push(kafka_test_topic('delay'), ['n' => 1], ['delay' => 60]);
})->throws(queue_exception::class);
