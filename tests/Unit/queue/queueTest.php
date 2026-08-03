<?php

use plato\exception\queue_exception;
use plato\plato;
use plato\queue\delayable;
use plato\queue\driver;
use plato\queue\message;
use plato\queue\queue;

plato::registry(plato_test_config());

/**
 * Records what a driver was asked to do, so the facade can be tested without a backend.
 *
 * A trait keeps the two test drivers small while every object owns its own call log.
 */
trait records_driver_calls
{
    /** @var array<int, array<int, mixed>> */
    public $calls = [];

    /** @var array<string, mixed> */
    public $config = [];

    public function configure(array $config): void
    {
        $this->config  = $config;
        $this->calls[] = ['configure', $config];
    }

    public function push(string $queue, $data, array $options = [])
    {
        $this->calls[] = ['push', $queue, $data, $options];

        return 'id-' . $queue;
    }

    public function pop($queues, int $timeout_ms = 1000)
    {
        $this->calls[] = ['pop', $queues, $timeout_ms];

        return new message(is_array($queues) ? reset($queues) : $queues, 'payload');
    }

    public function ack(message $msg): bool
    {
        $this->calls[] = ['ack', $msg->id()];

        return true;
    }

    public function release(message $msg, int $delay = 0): bool
    {
        $this->calls[] = ['release', $msg->id(), $delay];

        return true;
    }

    public function fail(message $msg, ?string $error = null): bool
    {
        $this->calls[] = ['fail', $msg->id(), $error];

        return true;
    }

    public function size(string $queue): int
    {
        $this->calls[] = ['size', $queue];

        return 7;
    }

    public function close(): bool
    {
        $this->calls[] = ['close'];

        return true;
    }
}

class fake_plain_driver implements driver
{
    use records_driver_calls;
}

class fake_delay_driver implements driver, delayable
{
    use records_driver_calls;

    public function push_delay(string $queue, $data, int $delay, array $options = [])
    {
        $this->calls[] = ['push_delay', $queue, $data, $delay, $options];

        return 'delayed-' . $queue;
    }

    public function migrate_delayed($queues, int $limit = 128): array
    {
        $this->calls[] = ['migrate_delayed', $queues, $limit];

        return [3, 1700000000];
    }
}

class not_a_driver
{
}

beforeEach(function () {
    queue::configure([
        'default'     => 'plain',
        'connections' => [
            'plain'   => ['driver' => fake_plain_driver::class, 'prefix' => 'p:'],
            'delayed' => ['driver' => fake_delay_driver::class, 'prefix' => 'd:'],
            'unknown' => ['driver' => 'nope'],
            'bad'     => ['driver' => not_a_driver::class],
        ],
    ]);
});

it('takes the default connection from the configuration', function () {
    expect(queue::config('default'))->toBe('plain')
        ->and(queue::connection())->toBeInstanceOf(fake_plain_driver::class);
});

it('configures a driver when that connection is first requested', function () {
    $driver = queue::driver();

    expect($driver)->toBeInstanceOf(fake_plain_driver::class)
        ->and($driver->calls[0][0])->toBe('configure')
        ->and($driver->calls[0][1]['prefix'])->toBe('p:');
});

it('keeps named connections independent without changing the default', function () {
    $plain   = queue::connection();
    $delayed = queue::connection('delayed');

    expect($plain)->toBeInstanceOf(fake_plain_driver::class)
        ->and($delayed)->toBeInstanceOf(fake_delay_driver::class)
        ->and($plain)->not->toBe($delayed)
        ->and(queue::connection())->toBe($plain)
        ->and($plain->config['prefix'])->toBe('p:')
        ->and($delayed->config['prefix'])->toBe('d:');
});

it('forwards every call to the active driver', function () {
    expect(queue::push('emails', ['a' => 1]))->toBe('id-emails');
    expect(queue::size('emails'))->toBe(7);
    expect(queue::pop('emails', 250))->toBeInstanceOf(message::class);

    $msg = new message('emails', 1);
    expect(queue::ack($msg))->toBeTrue();
    expect(queue::release($msg, 5))->toBeTrue();
    expect(queue::fail($msg, 'boom'))->toBeTrue();

    $names = array_column(queue::connection()->calls, 0);
    expect($names)->toBe(['configure', 'push', 'size', 'pop', 'ack', 'release', 'fail']);
});

it('routes a delayed push to push_delay', function () {
    queue::configure(['default' => 'delayed']);

    expect(queue::push('emails', ['a' => 1], ['delay' => 60]))->toBe('delayed-emails');

    $call = queue::connection()->calls[1];
    expect($call[0])->toBe('push_delay');
    expect($call[3])->toBe(60);
    // The facade consumes `delay`, the driver must not see it twice
    expect($call[4])->not->toHaveKey('delay');
});

it('treats a delay of zero or less as an immediate push', function () {
    queue::configure(['default' => 'delayed']);

    queue::push('emails', 1, ['delay' => 0]);
    queue::push('emails', 1, ['delay' => -5]);

    expect(array_column(queue::connection()->calls, 0))->toBe(['configure', 'push', 'push']);
});

it('refuses a delayed push on a driver that cannot delay', function () {
    queue::push('emails', 1, ['delay' => 60]);
})->throws(queue_exception::class, 'cannot delay');

it('reports the delay capability', function () {
    expect(queue::can_delay())->toBeFalse();

    expect(queue::can_delay('delayed'))->toBeTrue();
});

it('answers migrate_delayed without a backend when the driver cannot delay', function () {
    expect(queue::migrate_delayed('emails'))->toBe([0, 0]);

    expect(queue::connection('delayed')->migrate_delayed('emails', 10))->toBe([3, 1700000000]);
});

it('rejects a connection that is not configured', function () {
    queue::connection('nothing');
})->throws(queue_exception::class, 'is not configured');

it('rejects a driver class that does not exist', function () {
    queue::connection('unknown');
})->throws(queue_exception::class, 'does not exist');

it('rejects a class that is not a driver', function () {
    queue::connection('bad');
})->throws(queue_exception::class, 'does not implement');

it('rejects registering a class that is not a driver', function () {
    queue::register_driver('bad', not_a_driver::class);
})->throws(queue_exception::class, 'must implement');

it('resolves a registered short name', function () {
    queue::register_driver('faked', fake_delay_driver::class);
    $config = queue::config();
    $config['connections']['short'] = ['driver' => 'faked'];
    queue::configure($config);

    expect(queue::connection('short'))->toBeInstanceOf(fake_delay_driver::class);
});

it('falls back to the connection name when no driver key is given', function () {
    queue::register_driver('faked', fake_plain_driver::class);
    $config = queue::config();
    $config['connections']['faked'] = ['prefix' => 'f:'];
    queue::configure($config);

    expect(queue::connection('faked'))->toBeInstanceOf(fake_plain_driver::class);
});

it('closes without selecting a driver', function () {
    expect(queue::close())->toBeTrue();
});

it('complains when nothing is selected at all', function () {
    // `default` has to be named: configure() merges on top of whatever is in force, so an
    // override that says nothing about it keeps the connection that was selected before
    queue::configure(['connections' => [], 'default' => '']);
    queue::driver();
})->throws(queue_exception::class, 'no default queue connection is configured');
