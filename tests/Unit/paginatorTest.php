<?php
/**
 * paginator: page numbers and offsets, with no request and no configuration behind them.
 */

use plato\paginator;

it('computes pages, offset and the neighbours', function () {
    $meta = paginator::meta(95, 5, 10);

    expect($meta['total'])->toBe(95);
    expect($meta['total_page'])->toBe(10);
    expect($meta['current_page'])->toBe(5);
    expect($meta['page_size'])->toBe(10);
    expect($meta['offset'])->toBe(40);
    expect($meta['prev'])->toBe(4);
    expect($meta['next'])->toBe(6);
});

it('drops the neighbour that has nowhere to go', function () {
    $first = paginator::meta(25, 1, 10);
    expect($first['prev'])->toBeNull();
    expect($first['next'])->toBe(2);
    expect($first['offset'])->toBe(0);

    $last = paginator::meta(25, 3, 10);
    expect($last['prev'])->toBe(2);
    expect($last['next'])->toBeNull();
    expect($last['offset'])->toBe(20);
});

it('has nothing to page through when there are no rows', function () {
    $meta = paginator::meta(0);

    expect($meta['total'])->toBe(0);
    expect($meta['total_page'])->toBe(0);
    expect($meta['current_page'])->toBe(1);
    expect($meta['offset'])->toBe(0);
    expect($meta['prev'])->toBeNull();
    expect($meta['next'])->toBeNull();
});

it('keeps a page number past the end instead of clamping it', function () {
    // The caller asked for page 99 of a 3 page listing: it gets an offset past the end, so the
    // query comes back empty and the controller can answer 404. Clamping would serve page 3.
    $meta = paginator::meta(25, 99, 10);

    expect($meta['current_page'])->toBe(99);
    expect($meta['offset'])->toBe(980);
    expect($meta['next'])->toBeNull();
    expect($meta['prev'])->toBe(98);
});

it('floors the arguments instead of dividing by zero or paging backwards', function () {
    $meta = paginator::meta(-5, 0, 0);

    expect($meta['total'])->toBe(0);
    expect($meta['page_size'])->toBe(1);
    expect($meta['current_page'])->toBe(1);
    expect($meta['total_page'])->toBe(0);
    expect($meta['offset'])->toBe(0);
});

it('takes numeric strings, which is what a request hands over', function () {
    $meta = paginator::meta('95', '5', '10');

    expect($meta['current_page'])->toBe(5);
    expect($meta['offset'])->toBe(40);
    expect($meta['total_page'])->toBe(10);
});
