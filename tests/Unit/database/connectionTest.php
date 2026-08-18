<?php

/**
 * connection: the registry, the retry loop, transactions and the query log.
 *
 * The fake driver records what it was handed and answers from a canned list, so everything the base
 * class does around a statement can be checked without a reachable server. What the statement
 * itself looks like belongs to the grammar, not here.
 */

use plato\config;
use plato\database\connection;
use plato\database\db;

class fake_driver extends connection
{
    /** @var array<int, array{sql: string, bindings: array<int, mixed>, write: bool}> */
    public array $ran = [];

    /** @var array<int, string> begin / commit / rollback / savepoint calls, in order */
    public array $transactions = [];

    /** @var int Times a handle was dialled */
    public int $dials = 0;

    /** @var array<int, Throwable> Thrown in turn instead of running the statement */
    public array $failures = [];

    /** @var bool Whether _rollback() throws instead of answering, as a dead socket would */
    public bool $rollback_fails = false;

    /** @var array<int, array<string, mixed>> Rows _fetch() answers with */
    public array $rows = [];

    /** @var object|null */
    protected $_open = null;

    protected function _handle(bool $write)
    {
        if ($this->_open === null)
        {
            $this->dials++;
            $this->_open = new stdClass();
        }

        return $this->_open;
    }

    protected function _disconnect(): void
    {
        $this->_open = null;
    }

    protected function _fetch($handle, string $sql, array $bindings): array
    {
        $this->_record($sql, $bindings, false);

        return $this->rows;
    }

    protected function _affect($handle, string $sql, array $bindings): int
    {
        $this->_record($sql, $bindings, true);

        return 1;
    }

    protected function _insert($handle, string $sql, array $bindings)
    {
        $this->_record($sql, $bindings, true);

        return 42;
    }

    protected function _begin($handle): bool
    {
        $this->transactions[] = 'begin';

        return true;
    }

    protected function _commit($handle): bool
    {
        $this->transactions[] = 'commit';

        return true;
    }

    protected function _rollback($handle): bool
    {
        $this->transactions[] = 'rollback';

        if ($this->rollback_fails)
        {
            throw new RuntimeException('gone away');
        }

        return true;
    }

    protected function _savepoint($handle, string $name): bool
    {
        $this->transactions[] = 'savepoint ' . $name;

        return true;
    }

    protected function _release_savepoint($handle, string $name): bool
    {
        $this->transactions[] = 'release ' . $name;

        return true;
    }

    protected function _rollback_savepoint($handle, string $name): bool
    {
        $this->transactions[] = 'rollback to ' . $name;

        return true;
    }

    protected function _is_lost_connection(Throwable $e): bool
    {
        return $e->getMessage() === 'gone away';
    }

    protected function _is_deadlock(Throwable $e): bool
    {
        return $e->getMessage() === 'deadlock';
    }

    /**
     * @param  string            $sql
     * @param  array<int, mixed> $bindings
     * @param  bool              $write
     * @return void
     */
    private function _record(string $sql, array $bindings, bool $write): void
    {
        $this->ran[] = ['sql' => $sql, 'bindings' => $bindings, 'write' => $write];

        if ($this->failures)
        {
            throw array_shift($this->failures);
        }
    }
}

/**
 * @param array<string, mixed> $config
 * @return fake_driver
 */
function fake_connection(array $config = []): fake_driver
{
    return new fake_driver('fake', array_merge(['driver' => 'fake', 'prefix' => 'plt'], $config));
}

beforeEach(function () {
    $this->logging = db::logging_override();

    db::flush_log();
    // The query log is off in CLI unless debug is on, and these tests assert on it
    db::set_logging(true);
});

afterEach(function () {
    db::flush_log();
    db::set_logging($this->logging);

    connection::set_default(null);
});

it('expands the table prefix and hands the statement over with its values apart', function () {
    $db = fake_connection();

    $db->table('#PB#_user')->where('id', 5)->where('name', 'like', 'a%')->get();

    expect($db->ran[0]['sql'])->toBe('SELECT * FROM `plt_user` WHERE `id` = ? AND `name` LIKE ?')
        ->and($db->ran[0]['bindings'])->toBe([5, 'a%'])
        ->and($db->ran[0]['write'])->toBeFalse();
});

it('expands the table prefix in a raw statement too', function () {
    $db = fake_connection();

    $db->select_raw('SELECT * FROM #PB#_user WHERE id = ?', [7]);
    $db->statement('DROP TABLE #!PB#_literal');

    expect($db->ran[0]['sql'])->toBe('SELECT * FROM plt_user WHERE id = ?')
        // #!PB# is the escape for a name that really does contain the placeholder
        ->and($db->ran[1]['sql'])->toBe('DROP TABLE #PB#_literal');
});

it('sends a write to the write handle and answers with the insert id', function () {
    $db = fake_connection();

    expect($db->table('#PB#_user')->insert(['name' => 'a']))->toBe(42)
        ->and($db->ran[0]['write'])->toBeTrue();
});

it('records every statement in the query log with the connection it ran on', function () {
    $db = fake_connection();

    $db->table('#PB#_user')->where('id', 1)->get();

    expect(db::queries())->toHaveCount(1)
        ->and(db::queries()[0]['connection'])->toBe('fake')
        ->and(db::queries()[0]['bindings'])->toBe([1])
        ->and(db::queries()[0]['write'])->toBeFalse()
        ->and(db::last_query()['sql'])->toBe('SELECT * FROM `plt_user` WHERE `id` = ?')
        ->and(db::total_time())->toBeGreaterThanOrEqual(0.0);
});

it('drops the oldest entry rather than growing the query log forever', function () {
    $limit = db::log_limit();
    db::set_log_limit(2);

    try
    {
        $db = fake_connection();
        foreach ([1, 2, 3] as $id)
        {
            $db->table('#PB#_user')->where('id', $id)->get();
        }
    }
    finally
    {
        db::set_log_limit($limit);
    }

    expect(db::queries())->toHaveCount(2)
        ->and(array_column(db::queries(), 'bindings'))->toBe([[2], [3]]);
});

it('dials again and retries the statement when the connection was lost', function () {
    $db = fake_connection();
    $db->failures = [new RuntimeException('gone away')];

    $db->table('#PB#_user')->where('id', 1)->get();

    // Same statement twice, on a second handle
    expect($db->ran)->toHaveCount(2)
        ->and($db->ran[1]['sql'])->toBe($db->ran[0]['sql'])
        ->and($db->dials)->toBe(2)
        // Only the attempt that succeeded is logged
        ->and(db::queries())->toHaveCount(1);
});

it('retries a deadlock without dropping the connection', function () {
    $db = fake_connection();
    $db->failures = [new RuntimeException('deadlock')];

    $db->table('#PB#_user')->where('id', 1)->get();

    expect($db->ran)->toHaveCount(2)
        ->and($db->dials)->toBe(1);
});

it('gives up once it is out of attempts and says which statement failed', function () {
    $db = fake_connection(['max_retries' => 1]);
    $db->failures = [new RuntimeException('gone away'), new RuntimeException('gone away')];

    expect(fn () => $db->table('#PB#_user')->where('id', 1)->get())
        ->toThrow(RuntimeException::class, 'SELECT * FROM `plt_user`');
});

it('does not retry inside a transaction, where reconnecting would lose the writes', function () {
    $db = fake_connection();
    $db->failures = [new RuntimeException('gone away')];

    $db->begin();

    expect(fn () => $db->table('#PB#_user')->where('id', 1)->get())->toThrow(RuntimeException::class)
        ->and($db->ran)->toHaveCount(1);
});

it('nests transactions as savepoints and only commits at the outermost level', function () {
    $db = fake_connection();

    $db->begin();
    $db->begin();
    expect($db->transaction_level())->toBe(2);
    $db->commit();
    $db->commit();

    expect($db->transactions)->toBe(['begin', 'savepoint plato_sp_1', 'release plato_sp_1', 'commit'])
        ->and($db->in_transaction())->toBeFalse();
});

it('rolls an inner transaction back to its savepoint', function () {
    $db = fake_connection();

    $db->begin();
    $db->begin();
    $db->rollback();
    $db->rollback();

    expect($db->transactions)->toBe(['begin', 'savepoint plato_sp_1', 'rollback to plato_sp_1', 'rollback']);
});

it('commits a closure that returns and rolls back one that throws', function () {
    $db = fake_connection();

    expect($db->transaction(fn () => 'done'))->toBe('done')
        ->and($db->transactions)->toBe(['begin', 'commit']);

    expect(fn () => $db->transaction(function () {
        throw new RuntimeException('nope');
    }))->toThrow(RuntimeException::class, 'nope');

    expect($db->transactions)->toBe(['begin', 'commit', 'begin', 'rollback'])
        ->and($db->in_transaction())->toBeFalse();
});

it('ignores commit and rollback when no transaction is open', function () {
    $db = fake_connection();

    expect($db->commit())->toBeFalse()
        ->and($db->rollback())->toBeFalse()
        ->and($db->transactions)->toBe([]);
});

it('forgets the transaction depth a fork invalidated', function () {
    $db = fake_connection();

    $db->begin();
    expect($db->in_transaction())->toBeTrue();

    // What a fork looks like from the child's side: same object, one epoch further on. The socket
    // itself needs no help here -- it is a plato\runtime entry and the registry drops the whole map
    // on a fork -- but the belief that a transaction is open was the parent's, and a child acting
    // on it would COMMIT a transaction it never opened
    $epoch = new ReflectionProperty(connection::class, '_epoch');
    $epoch->setAccessible(true);
    $epoch->setValue($db, $epoch->getValue($db) - 1);

    $db->table('#PB#_user')->where('id', 2)->get();

    expect($db->in_transaction())->toBeFalse();
});

it('hands out one instance per name and refuses a name that is not configured', function () {
    $db = config::instance('database');
    $connections = $db->get('connections');

    $db->set('connections.unit_fake', ['driver' => 'fake', 'prefix' => 'plt']);
    connection::register_driver('fake', fake_driver::class);

    try
    {
        $first = connection::instance('unit_fake');

        expect($first)->toBeInstanceOf(fake_driver::class)
            ->and(connection::instance('unit_fake'))->toBe($first)
            ->and($first->name())->toBe('unit_fake')
            ->and($first->prefix())->toBe('plt');

        connection::set_default('unit_fake');
        expect(connection::instance())->toBe($first);

        expect(fn () => connection::instance('no_such_connection'))
            ->toThrow(RuntimeException::class, 'no_such_connection');

        connection::purge('unit_fake');
        expect(connection::instance('unit_fake'))->not->toBe($first);
    }
    finally
    {
        connection::purge('unit_fake');
        $db->set('connections', $connections);
    }
});

it('rolls back a transaction a request left open, and says how deep it was', function () {
    $config      = config::instance('database');
    $connections = $config->get('connections');

    $config->set('connections.unit_left_open', ['driver' => 'fake', 'prefix' => 'plt']);
    connection::register_driver('fake', fake_driver::class);

    try
    {
        $db = connection::instance('unit_left_open');
        $db->begin();
        $db->begin();

        // One outermost rollback whatever the depth: it takes the savepoint with it
        expect(connection::discard_transactions())->toBe(['unit_left_open' => 2])
            ->and($db->in_transaction())->toBeFalse()
            ->and($db->transactions)->toBe(['begin', 'savepoint plato_sp_1', 'rollback'])
            // Nothing open now, so the next request boundary has nothing to report
            ->and(connection::discard_transactions())->toBe([]);
    }
    finally
    {
        connection::purge('unit_left_open');
        $config->set('connections', $connections);
    }
});

it('disconnects a connection whose rollback fails instead of letting it through', function () {
    $config      = config::instance('database');
    $connections = $config->get('connections');

    $config->set('connections.unit_dead_socket', ['driver' => 'fake', 'prefix' => 'plt']);
    connection::register_driver('fake', fake_driver::class);

    try
    {
        $db = connection::instance('unit_dead_socket');
        $db->begin();
        $db->rollback_fails = true;

        // The worker is between two messages: a throw here would end its loop over a transaction
        // the last message left behind
        expect(connection::discard_transactions())->toBe(['unit_dead_socket' => 1])
            ->and($db->in_transaction())->toBeFalse();

        $db->rollback_fails = false;
        $dials              = $db->dials;

        $db->select_raw('select 1');

        // Disconnected, so the next statement dials rather than reusing a socket that failed
        expect($db->dials)->toBe($dials + 1);
    }
    finally
    {
        connection::purge('unit_dead_socket');
        $config->set('connections', $connections);
    }
});

it('builds a connection from settings that never went through config', function () {
    connection::register_driver('fake', fake_driver::class);

    $db = connection::make('fake', ['prefix' => 'adhoc'], 'side');

    expect($db)->toBeInstanceOf(fake_driver::class)
        ->and($db->name())->toBe('side')
        ->and($db->table_prefix('#PB#_user'))->toBe('adhoc_user');
});
