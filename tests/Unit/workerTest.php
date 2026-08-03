<?php

/**
 * plato\worker: which of its group's processes this one is.
 *
 * No fork here. What is under test is the arithmetic and the out-of-group answers, and both are
 * plain static state; tests/Feature/poolTest.php is where a real pool claims real children.
 */

use plato\worker;

afterEach(function () {
    worker::leave();
});

it('is in no group until something claims it', function () {
    expect(worker::index())->toBe(-1)
        ->and(worker::count())->toBe(0)
        ->and(worker::in_group())->toBeFalse();
});

it('reports the index and count it was entered with', function () {
    worker::enter(2, 4);

    expect(worker::index())->toBe(2)
        ->and(worker::count())->toBe(4)
        ->and(worker::in_group())->toBeTrue();
});

it('goes back to being in no group when it leaves', function () {
    worker::enter(1, 3);
    worker::leave();

    expect(worker::index())->toBe(-1)
        ->and(worker::count())->toBe(0)
        ->and(worker::in_group())->toBeFalse();
});

it('gives every key to exactly one worker of the group', function () {
    $owners = [];

    foreach ( range(0, 3) as $index )
    {
        worker::enter($index, 4);

        foreach ( range(0, 19) as $key )
        {
            if ( worker::owns($key) )
            {
                $owners[$key][] = $index;
            }
        }
    }

    expect($owners)->toHaveCount(20);

    // One owner each, and no key without one -- which is the whole point of asking instead of
    // taking a lock
    foreach ( $owners as $key => $claimed )
    {
        expect($claimed)->toHaveCount(1, "key {$key} was claimed by " . implode(',', $claimed));
    }
});

it('spreads the keys over the workers rather than piling them on one', function () {
    $mine = 0;

    worker::enter(0, 4);

    foreach ( range(0, 99) as $key )
    {
        worker::owns($key) && $mine++;
    }

    expect($mine)->toBe(25);
});

it('claims a negative key too, instead of owning nothing', function () {
    // % of a negative left-hand side is negative in PHP, so an id arriving as -7 would match no
    // index at all and the work would silently go undone
    worker::enter(3, 4);

    // -7 lands on 3 the way 7 does, and -5 lands on 1 the way 5 does
    expect(worker::owns(-7))->toBeTrue()
        ->and(worker::owns(-5))->toBeFalse();
});

it('owns everything when it is in no group, so a lone process still does the work', function () {
    expect(worker::owns())->toBeTrue()
        ->and(worker::owns(7))->toBeTrue()
        ->and(worker::owns(41))->toBeTrue();
});

it('treats a nonsense index or count as being in no group', function () {
    worker::enter(-5, -2);

    expect(worker::index())->toBe(-1)
        ->and(worker::count())->toBe(0)
        ->and(worker::in_group())->toBeFalse();

    worker::enter(4, 4);

    expect(worker::index())->toBe(-1)
        ->and(worker::count())->toBe(0)
        ->and(worker::in_group())->toBeFalse();
});
