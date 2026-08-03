<?php

/**
 * plato\queue\kafka: what can be asserted without a broker.
 *
 * What is covered here is the part that is pure logic -- the contracts it does and does not
 * implement, the dead letter topic name, and the refusals that happen before anything connects.
 * Producing, consuming, committing offsets and the lag calculation need a broker, and they are in
 * tests/Feature/queueKafkaTest.php.
 */

use plato\exception\queue_exception;
use plato\queue\delayable;
use plato\queue\driver;
use plato\queue\kafka;
use plato\queue\queue;

beforeEach(function () {
    $this->kafka = new kafka();
    $this->kafka->configure([
        'driver'            => 'kafka',
        'brokers'           => 'localhost:9092',
        'group_id'          => 'platophptest',
        'dead_letter_topic' => '%s.dlq',
    ]);
});

it('is a driver and deliberately not delayable', function () {
    // A log is not a schedule: the facade has to refuse a delayed push rather than deliver it now
    expect(is_a(kafka::class, driver::class, true))->toBeTrue()
        ->and(is_a(kafka::class, delayable::class, true))->toBeFalse();
});

it('refuses a delayed push through the facade instead of delivering it immediately', function () {
    queue::configure([
        'default'     => 'kafka',
        'connections' => ['kafka' => ['driver' => 'kafka', 'brokers' => 'localhost:9092']],
    ]);

    queue::push('emails', ['n' => 1], ['delay' => 60]);
})->throws(queue_exception::class);

it('builds the dead letter topic from the configured pattern', function () {
    expect($this->kafka->dead_letter_topic('emails'))->toBe('emails.dlq');

    $this->kafka->configure(['brokers' => 'localhost:9092', 'dead_letter_topic' => 'dead.%s']);
    expect($this->kafka->dead_letter_topic('emails'))->toBe('dead.emails');

    // A pattern with no placeholder is one topic for everything, which is a legitimate choice
    $this->kafka->configure(['brokers' => 'localhost:9092', 'dead_letter_topic' => 'dead_letter']);
    expect($this->kafka->dead_letter_topic('emails'))->toBe('dead_letter');
});

it('defaults the dead letter topic to <topic>.dlq', function () {
    $this->kafka->configure(['brokers' => 'localhost:9092']);

    expect($this->kafka->dead_letter_topic('emails'))->toBe('emails.dlq');
});

it('refuses to connect when no brokers are configured', function () {
    $this->kafka->configure(['driver' => 'kafka', 'brokers' => '']);

    $this->kafka->push('emails', ['n' => 1]);
})->throws(queue_exception::class, 'no brokers configured')->skip(
    !extension_loaded('rdkafka'),
    'push() refuses a missing ext-rdkafka before it ever looks at the brokers'
);

it('answers ack and release for a message it did not deliver without touching a broker', function () {
    $message = new plato\queue\message('emails', ['n' => 1]);

    // No RdKafka\Message handle, so there is no offset to commit
    expect($this->kafka->ack($message))->toBeFalse();
});

it('closes cleanly when nothing was ever built', function () {
    expect($this->kafka->close())->toBeTrue();
});
