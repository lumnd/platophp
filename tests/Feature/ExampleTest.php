<?php

it('example', function () {
    expect(true)->toBeTrue();
    expect(1)->toBeInt();
    expect(1.0)->toBeFloat();
    expect("test")->toBeString();
    expect("111")->toBeNumeric();
    expect(["111", "222"])->toBeArray();
})->skip("Test only");
