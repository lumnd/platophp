<?php

/**
 * plato\queue\worker: the consume loop and the retry policy, over an in-process driver.
 *
 * No redis. What is under test is the policy -- how many attempts a message gets, where it goes
 * when it runs out of them, what stops the loop -- and that is the worker's own work, not a
 * backend's. tests/Feature/queueRedisTest.php is where the driver itself is exercised.
 */

use plato\exception\queue_exception;
use plato\http\req;
use plato\http\route;
use plato\log;
use plato\plato;
use plato\queue\delayable;
use plato\queue\driver;
use plato\queue\message;
use plato\queue\queue;
use plato\queue\worker;

plato::registry(plato_test_config());

/**
 * A queue in an array: enough of a backend for the worker to be driven against.
 */
class array_queue_driver implements driver, delayable
{
    /** @var array<string, array<int, string>> */
    public $ready = [];

    /** @var array<int, array{0: string, 1: int}> */
    public $delayed = [];

    /** @var array<int, string> */
    public $failed = [];

    /** @var array<int, string> */
    public $acked = [];

    /** @var bool */
    public $ack_ok = true;

    /** @var bool */
    public $release_ok = true;

    /** @var bool */
    public $fail_ok = true;

    public function configure(array $config): void
    {
    }

    public function push(string $queue, $data, array $options = [])
    {
        $message = new message($queue, $data);

        $this->ready[$queue][] = $message->encode();

        return $message->id();
    }

    public function push_delay(string $queue, $data, int $delay, array $options = [])
    {
        $message = new message($queue, $data, ['delay' => $delay]);

        $this->delayed[] = [$message->encode(), time() + $delay];

        return $message->id();
    }

    public function pop($queues, int $timeout_ms = 1000)
    {
        foreach ( (array) $queues as $queue )
        {
            if ( !empty($this->ready[$queue]) )
            {
                return message::decode(array_shift($this->ready[$queue]));
            }
        }

        return null;
    }

    public function ack(message $msg): bool
    {
        if ( !$this->ack_ok )
        {
            return false;
        }

        $this->acked[] = $msg->id();

        return true;
    }

    public function release(message $msg, int $delay = 0): bool
    {
        if ( !$this->release_ok )
        {
            return false;
        }

        if ( $delay > 0 )
        {
            $this->delayed[] = [$msg->encode(), time() + $delay];

            return true;
        }

        $this->ready[$msg->queue()][] = $msg->encode();

        return true;
    }

    public function fail(message $msg, ?string $error = null): bool
    {
        if ( !$this->fail_ok )
        {
            return false;
        }

        $msg->set_error($error);

        $this->failed[] = $msg->encode();

        return true;
    }

    public function size(string $queue): int
    {
        return count($this->ready[$queue] ?? []);
    }

    public function close(): bool
    {
        return true;
    }

    public function migrate_delayed($queues, int $limit = 128): array
    {
        $moved   = 0;
        $next_at = 0;
        $now     = time();

        foreach ( $this->delayed as $index => [$payload, $due] )
        {
            if ( $due <= $now )
            {
                $message = message::decode($payload);
                $this->ready[$message->queue()][] = $payload;
                unset($this->delayed[$index]);
                $moved++;

                continue;
            }

            if ( $next_at === 0 || $due < $next_at )
            {
                $next_at = $due;
            }
        }

        $this->delayed = array_values($this->delayed);

        return [$moved, $next_at];
    }
}

beforeEach(function () {
    queue::configure([
        'default'     => 'array',
        'connections' => ['array' => ['driver' => array_queue_driver::class]],
    ]);
});

it('runs a message and acknowledges it', function () {
    $seen = [];

    queue::push('default', ['n' => 1]);

    $stats = worker::run([
        'queues'  => ['default'],
        'once'    => true,
        'handler' => function (message $msg) use (&$seen) {
            $seen[] = $msg->payload();
        },
    ]);

    expect($seen)->toBe([['n' => 1]])
        ->and($stats['processed'])->toBe(1)
        ->and($stats['failed'])->toBe(0)
        ->and($stats['stopped'])->toBe('once')
        ->and(queue::connection()->acked)->toHaveCount(1);
});

it('does not count a message as done when acknowledgement fails', function () {
    queue::push('default', 'payload');
    queue::connection()->ack_ok = false;

    $stats = worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 1,
        'handler'      => function () {
        },
    ]);

    expect($stats['processed'])->toBe(1)
        ->and($stats['failed'])->toBe(1)
        ->and(queue::connection()->acked)->toBeEmpty()
        ->and(message::decode(queue::connection()->failed[0])->error())
        ->toContain('could not be acknowledged');
});

it('raises an error when a retry cannot be persisted', function () {
    queue::push('default', 'payload');
    queue::connection()->release_ok = false;

    worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 2,
        'backoff'      => [0],
        'handler'      => function () {
            throw new RuntimeException('nope');
        },
    ]);
})->throws(queue_exception::class, 'could not be released');

it('raises an error when a terminal failure cannot be persisted', function () {
    queue::push('default', 'payload');
    queue::connection()->fail_ok = false;

    worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 1,
        'handler'      => function () {
            throw new RuntimeException('nope');
        },
    ]);
})->throws(queue_exception::class, 'could not be moved to the failed destination');

it('releases a message that threw, for another attempt', function () {
    queue::push('default', 'payload');

    $stats = worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 3,
        'backoff'      => [7],
        'handler'      => function () {
            throw new RuntimeException('nope');
        },
    ]);

    expect($stats['released'])->toBe(1)
        ->and($stats['failed'])->toBe(0)
        ->and(queue::connection()->failed)->toBeEmpty()
        ->and(queue::connection()->delayed)->toHaveCount(1);

    // The delay comes off the backoff list, and the attempt is counted on the envelope
    [$payload, $due] = queue::connection()->delayed[0];

    expect($due)->toBeGreaterThan(time() + 5)
        ->and(message::decode($payload)->attempts())->toBe(1);
});

it('gives up on a message once it runs out of attempts', function () {
    // An envelope that has already been delivered twice, so the next failure is its last
    queue::connection()->ready['default'][] = (new message('default', 'payload', ['attempts' => 2]))->encode();

    $stats = worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 3,
        'handler'      => function () {
            throw new RuntimeException('still nope');
        },
    ]);

    expect($stats['failed'])->toBe(1)
        ->and($stats['released'])->toBe(0)
        ->and(queue::connection()->failed)->toHaveCount(1)
        ->and(message::decode(queue::connection()->failed[0])->error())->toBe('still nope')
        ->and(message::decode(queue::connection()->failed[0])->attempts())->toBe(3);
});

it('fails a message that has neither a handler nor a routable payload', function () {
    queue::push('default', 'just a string');

    $stats = worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 1,
    ]);

    expect($stats['failed'])->toBe(1)
        ->and(message::decode(queue::connection()->failed[0])->error())->toContain('has no handler');
});

it('does not count a message it could not dispatch as done', function () {
    // A payload naming a controller is what the worker dispatches when it has no handler; this one
    // names a controller that is not there, and plato::run() answers false rather than throwing
    queue::push('default', ['ct' => 'nosuchcontroller', 'ac' => 'index']);

    $stats = worker::run([
        'queues'       => ['default'],
        'once'         => true,
        'max_attempts' => 1,
    ]);

    expect($stats['failed'])->toBe(1)
        ->and(message::decode(queue::connection()->failed[0])->error())
        ->toContain('could not be dispatched to nosuchcontroller:index');

    // plato::run() left a resolved route and the payload behind; the next case gets a clean one
    route::reset();
    req::reset_input();
});

it('serves a callable handler behind the same request boundary a routed payload gets', function () {
    // Callable handlers get the same clock, request id, and identity reset as routed payloads.
    queue::push('default', 'first');
    queue::push('default', 'second');

    plato::$auth = 'left over from before the worker started';

    $rids = [];
    $auth = [];

    worker::run([
        'queues'   => ['default'],
        'max_jobs' => 2,
        'handler'  => function () use (&$rids, &$auth) {
            $rids[] = log::shared_context()['rid'] ?? '';
            $auth[] = plato::$auth;

            // Left behind on purpose: the message after this one must not be dispatched as it
            plato::$auth = 'set by the handler';
        },
    ]);

    expect($auth)->toBe([null, null])
        ->and($rids)->toHaveCount(2)
        ->and($rids[0])->not->toBeEmpty()
        ->and($rids[1])->not->toBe($rids[0]);
});

it('stops after the job count it was given', function () {
    foreach ([1, 2, 3] as $n)
    {
        queue::push('default', $n);
    }

    $stats = worker::run([
        'queues'   => ['default'],
        'max_jobs' => 2,
        'handler'  => function () {
        },
    ]);

    expect($stats['processed'])->toBe(2)
        ->and($stats['stopped'])->toBe('max_jobs')
        ->and(queue::connection()->size('default'))->toBe(1);
});

it('moves due messages onto the queue before reading it', function () {
    // Due a second ago, so the first migrate of the loop has to pick it up
    queue::connection()->delayed[] = [(new message('default', 'late'))->encode(), time() - 1];

    $seen = [];

    $stats = worker::run([
        'queues'  => ['default'],
        'once'    => true,
        'handler' => function (message $msg) use (&$seen) {
            $seen[] = $msg->payload();
        },
    ]);

    expect($seen)->toBe(['late'])
        ->and($stats['processed'])->toBe(1);
});

it('reads several queues in the order it was given them', function () {
    queue::push('low', 'low');
    queue::push('high', 'high');

    $seen = [];

    worker::run([
        'queues'  => ['high', 'low'],
        'once'    => true,
        'handler' => function (message $msg) use (&$seen) {
            $seen[] = $msg->payload();
        },
    ]);

    expect($seen)->toBe(['high', 'low']);
});

it('refuses to run without a queue to read', function () {
    expect(fn () => worker::run(['queues' => []]))
        ->toThrow(queue_exception::class);
});
