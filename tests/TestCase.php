<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for both suites.
 *
 * The framework is a static facade, so there is nothing to build per test. This exists to give
 * the test closures a $this to hang per test state on, the way the migrator tests do.
 */
abstract class TestCase extends BaseTestCase
{
}
