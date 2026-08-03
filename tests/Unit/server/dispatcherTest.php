<?php

/**
 * server\dispatcher: one message in, one controller call out, and nothing left behind.
 *
 * The whole point of the driver interface is that this needs no event loop to test:
 * fake_server_driver is an array with seven methods around it, and the controller below is an
 * ordinary controller.
 */

namespace control;

use plato\debug\benchmark;
use plato\http\req;
use plato\http\resp;
use plato\plato;
use plato\server\connection;
use plato\server\dispatcher;
use plato\server\driver;

plato::registry(plato_test_config());

/**
 * Driver that holds its connections in an array and records what it was told to write.
 */
class fake_server_driver implements driver
{
    /** @var array<string, mixed> */
    public $config = [];

    /** @var array<int, array<int, mixed>> */
    public $sent = [];

    /** @var array<int, array<int, mixed>> */
    public $closed = [];

    /** @var bool */
    public $running = false;

    /** @var array<string, connection> */
    private $_conns = [];

    public function configure(array $config): void
    {
        $this->config = $config;
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function send(string $id, string $payload): bool
    {
        $this->sent[] = [$id, $payload];

        return isset($this->_conns[$id]);
    }

    public function close(string $id, int $code = 1000, string $reason = ''): bool
    {
        $this->closed[] = [$id, $code, $reason];

        if ( !isset($this->_conns[$id]) )
        {
            return false;
        }

        $this->_conns[$id]->mark_closed();
        unset($this->_conns[$id]);

        return true;
    }

    public function connection(string $id)
    {
        return $this->_conns[$id] ?? null;
    }

    public function connections(): array
    {
        return array_keys($this->_conns);
    }

    /**
     * Accept a connection, as the loop would on a handshake.
     */
    public function accept(string $id = 'c1'): connection
    {
        $conn = new connection($id, $this, '10.0.0.1:51000');

        $this->_conns[$id] = $conn;

        return $conn;
    }

    public function forget_all(): void
    {
        $this->config = [];
        $this->sent   = $this->closed = $this->_conns = [];
    }
}

/**
 * Controller the server tests route to. It prints one answer and returns another as a reply value.
 */
class ctl_server
{
    public function reflect()
    {
        echo json_encode([
            'code' => 0,
            'a'    => req::get('a'),
            'auth' => plato::$auth,
            'seq'  => dispatcher::seq(),
            'conn' => dispatcher::current() === null ? '' : dispatcher::current()->id(),
        ]);
    }

    public function quiet()
    {
    }

    public function respond()
    {
        return resp::response(0, ['transport' => 'server'], 'ok');
    }

    public function boom()
    {
        throw new \RuntimeException('the action exploded');
    }
}

beforeEach(function () {
    $this->driver = new fake_server_driver();

    dispatcher::configure([
        'max_payload'  => 128,
        'error_detail' => false,
    ]);
});

it('routes a message to a controller and returns what it printed', function () {
    $conn = $this->driver->accept();

    $reply = dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'reflect', 'a' => 'one']));

    expect($reply)->toBeString();

    $data = json_decode((string) $reply, true);

    expect($data['code'])->toBe(0);
    expect($data['a'])->toBe('one');
    expect($data['conn'])->toBe('c1');
});

it('returns a response value without emitting or ending the worker', function () {
    $conn  = $this->driver->accept();
    $reply = dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'respond']));
    $data  = json_decode((string) $reply, true);

    expect($data['code'])->toBe(0)
        ->and($data['data'])->toBe(['transport' => 'server'])
        ->and(dispatcher::current())->toBeNull();
});

it('clears the request input between two messages of the same worker', function () {
    $conn = $this->driver->accept();

    dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'reflect', 'a' => 'first']));

    $second = json_decode((string) dispatcher::handle(
        $conn,
        json_encode(['ct' => 'server', 'ac' => 'reflect'])
    ), true);

    expect($second['a'])->toBeNull();
});

it('dispatches as the identity stored on the connection, and forgets it afterwards', function () {
    $conn = $this->driver->accept();
    $conn->set(connection::AUTH, ['uid' => 7]);

    $data = json_decode((string) dispatcher::handle($conn, json_encode([
        'ct'  => 'server',
        'ac'  => 'reflect',
        'seq' => 'q-1',
    ])), true);

    expect($data['auth'])->toBe(['uid' => 7]);
    expect($data['seq'])->toBe('q-1');

    // The next message of another connection must not inherit it
    expect(plato::$auth)->toBeNull();
    expect(dispatcher::current())->toBeNull();
    expect(dispatcher::seq())->toBe('');
});

it('drops the benchmark marks of the previous message', function () {
    $conn = $this->driver->accept();

    benchmark::$marker['stale_start'] = ['time' => 1.0, 'mem' => 1];

    dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'quiet']));

    expect(benchmark::$marker)->not->toHaveKey('stale_start');
});

it('restamps the clock, so a message is not dated by the worker boot', function () {
    $conn = $this->driver->accept();

    // registry() ran when the suite started, so this has been counting for a while
    $before = plato::app_total()[0];

    dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'quiet']));

    expect(plato::app_total()[0])->toBeLessThan($before);
});

it('answers an unknown route with an error reply instead of throwing', function () {
    $conn = $this->driver->accept();

    $data = json_decode((string) dispatcher::handle($conn, json_encode([
        'ct' => 'nosuch',
        'ac' => 'index',
    ])), true);

    expect($data['code'])->toBe(dispatcher::CODE_NOT_FOUND);
});

it('answers a failed action with an error reply and keeps the worker alive', function () {
    $conn = $this->driver->accept();

    $data = json_decode((string) dispatcher::handle(
        $conn,
        json_encode(['ct' => 'server', 'ac' => 'boom'])
    ), true);

    expect($data['code'])->toBe(dispatcher::CODE_INTERNAL);
    // Not the exception message: error_detail is off
    expect($data['msg'])->toBe('internal error');
});

it('reports the exception message only when error_detail is on', function () {
    $conn = $this->driver->accept();

    dispatcher::configure(['error_detail' => true]);

    $data = json_decode((string) dispatcher::handle(
        $conn,
        json_encode(['ct' => 'server', 'ac' => 'boom'])
    ), true);

    expect($data['msg'])->toBe('the action exploded');
});

it('refuses a payload that is not a json object, and one that is too large', function () {
    $conn = $this->driver->accept();

    $bad = json_decode((string) dispatcher::handle($conn, 'not json at all'), true);
    expect($bad['code'])->toBe(dispatcher::CODE_BAD_MESSAGE);

    $big = json_decode((string) dispatcher::handle($conn, json_encode([
        'ct'  => 'server',
        'ac'  => 'reflect',
        'a'   => str_repeat('x', 256),
    ])), true);
    expect($big['code'])->toBe(dispatcher::CODE_BAD_MESSAGE);
    expect($big['msg'])->toBe('payload too large');
});

it('says nothing at all when the action printed nothing', function () {
    $conn = $this->driver->accept();

    expect(dispatcher::handle($conn, json_encode(['ct' => 'server', 'ac' => 'quiet'])))->toBeNull();
});

it('sends no error reply when error_reply is off', function () {
    $conn = $this->driver->accept();

    dispatcher::configure(['error_reply' => false]);

    expect(dispatcher::handle($conn, 'not json at all'))->toBeNull();
});

it('lets an open hook refuse a connection', function () {
    $conn   = $this->driver->accept();
    $handle = dispatcher::on('open', fn () => false);

    try
    {
        expect(dispatcher::open($conn))->toBeFalse();
    }
    finally
    {
        dispatcher::off($handle);
    }

    expect(dispatcher::open($conn))->toBeTrue();
});

it('establishes a clean request boundary before a message hook answers', function () {
    $conn = $this->driver->accept();
    $conn->set(connection::AUTH, ['uid' => 9]);

    req::$gets = ['stale' => true];

    $handle = dispatcher::on('message', function () {
        return [
            'code'  => 0,
            'pong'  => true,
            'stale' => req::get('stale'),
            'auth'  => plato::$auth,
        ];
    });

    try
    {
        $data = json_decode((string) dispatcher::handle($conn, json_encode(['ac' => 'ping'])), true);

        expect($data['pong'])->toBeTrue();
        expect($data['stale'])->toBeNull();
        expect($data['auth'])->toBe(['uid' => 9]);
    }
    finally
    {
        dispatcher::off($handle);
    }

    expect(plato::$auth)->toBeNull();
    expect(dispatcher::current())->toBeNull();
});

it('tells a close hook the connection is gone', function () {
    $conn   = $this->driver->accept();
    $seen   = null;
    $handle = dispatcher::on('close', function (connection $c) use (&$seen) {
        $seen = $c->id();
    });

    try
    {
        dispatcher::close($conn);
    }
    finally
    {
        dispatcher::off($handle);
    }

    expect($seen)->toBe('c1');
    expect($conn->is_open())->toBeFalse();
});

it('sends and closes through the driver holding the socket', function () {
    $conn = $this->driver->accept();

    expect($conn->send('raw payload'))->toBeTrue();
    expect($conn->send(['code' => 0]))->toBeTrue();

    expect($this->driver->sent[0][1])->toBe('raw payload');
    expect($this->driver->sent[1][1])->toBe('{"code":0}');

    expect($conn->close(1008, 'policy'))->toBeTrue();
    expect($this->driver->closed[0])->toBe(['c1', 1008, 'policy']);

    // Closed once, then nothing more goes out on it
    expect($conn->is_open())->toBeFalse();
    expect($conn->send('after close'))->toBeFalse();
    expect($this->driver->sent)->toHaveCount(2);
});

it('refuses to build a connection for something that is not a driver', function () {
    new connection('c9', \stdClass::class);
})->throws(\TypeError::class);
