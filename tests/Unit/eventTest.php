<?php
/**
 * event: the framework hook bus.
 */

use plato\event;
use plato\exception\event_exception;

it('binds a listener that keeps running', function () {
    $calls = [];
    event::on('test.on', function ($event, $id) use (&$calls) {
        $calls[] = [$event, $id];
    });

    expect(event::trigger('test.on', [1]))->toBeTrue();
    event::trigger('test.on', [2]);

    expect($calls)->toBe([['test.on', 1], ['test.on', 2]]);

    event::off('test.on');
});

it('binds a listener that runs a single time', function () {
    $calls = 0;
    event::one('test.one', function () use (&$calls) {
        $calls++;
    });

    event::trigger('test.one');
    expect(event::trigger('test.one'))->toBeFalse();
    expect($calls)->toBe(1);
});

it('honours the call limit of a binding', function () {
    $calls = 0;
    event::bind('test.bind', function () use (&$calls) {
        $calls++;
    }, 2);

    event::trigger('test.bind');
    event::trigger('test.bind');
    event::trigger('test.bind');

    expect($calls)->toBe(2);
});

it('reports nothing happened when no listener is bound', function () {
    expect(event::trigger('test.unbound'))->toBeFalse();
});

it('removes a single listener or the whole event', function () {
    $calls    = 0;
    $listener = function () use (&$calls) {
        $calls++;
    };

    $first = event::bind('test.off', $listener);
    event::bind('test.off', $listener);

    expect(event::off('test.off', $first))->toBeTrue();
    expect(event::off('test.off', $first))->toBeFalse();

    event::trigger('test.off');
    expect($calls)->toBe(1);

    expect(event::off('test.off'))->toBeTrue();
    expect(event::off('test.off'))->toBeFalse();
    expect(event::trigger('test.off'))->toBeFalse();
});

it('lets a listener unbind another one while the event is dispatched', function () {
    $calls   = 0;
    $handles = [];

    $handles[] = event::bind('test.reentrant', function () use (&$calls, &$handles) {
        $calls++;
        event::off('test.reentrant', $handles[1]);
    });
    $handles[] = event::bind('test.reentrant', function () use (&$calls) {
        $calls++;
    });

    event::trigger('test.reentrant');

    expect($calls)->toBe(1);
    event::off('test.reentrant');
});

it('rejects a handler that is not callable', function () {
    event::bind('test.invalid', ['model\nope', 'missing']);
})->throws(event_exception::class, 'Event handler [model\nope::missing] is not callable');

it('keeps the built in event names unique', function () {
    $events = [
        event::ON_EXCEPTION,
        event::ON_ERROR,
        event::ON_REQUEST,
        event::ON_RESPONSE,
        event::ON_FILTER,
        event::ON_SQL,
    ];

    expect(array_unique($events))->toHaveCount(count($events));
});
