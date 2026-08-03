<?php

/**
 * PSR-3 adapter: hands plato\log to anything that asks for a LoggerInterface
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\psr;

use plato\log;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * plato\log behind the PSR-3 interface.
 *
 * The point of this class is not to log; it is to be accepted. A library that wants somewhere to
 * write asks for a `Psr\Log\LoggerInterface`, and until now a PlatoPHP application had nothing to
 * give it:
 *
 *     $client = new SomeLibrary(new plato\psr\logger());
 *
 * Two things are worth knowing about it.
 *
 * **The method names are camelCase where the interface says so.** This package writes snake_case,
 * and that rule stops at an interface somebody else defined: `emergency` and `log` happen to look
 * the same either way, and this class has no other method a caller reaches for.
 *
 * **psr/log is not a dependency of this package.** It is a suggestion, so that installing a
 * framework does not pin the host project's psr/log major -- the typed signatures below only exist
 * in psr/log 3, and a project on 1 could not load this file at all. Nothing else in the framework
 * refers to this class, so an application that never installs psr/log never notices it.
 *
 * **There is almost nothing in it.** Interpolation, the leftover context and the rendering of an
 * `exception` key all belong to plato\log::write(), which speaks PSR-3 context natively. What is
 * left here is the interface, the level name mapping, and the cast of a Stringable message -- which
 * stays on this side on purpose: log::write() turns an object into its property names and never
 * its values, and a message with a __toString() is the one object a caller does mean to print.
 */
class logger implements LoggerInterface
{
    /**
     * PSR-3 level => plato\log level
     *
     * @var array<string, int>
     */
    private const LEVELS = [
        LogLevel::EMERGENCY => log::EMERGENCY,
        LogLevel::ALERT     => log::ALERT,
        LogLevel::CRITICAL  => log::CRITICAL,
        LogLevel::ERROR     => log::ERROR,
        LogLevel::WARNING   => log::WARNING,
        LogLevel::NOTICE    => log::NOTICE,
        LogLevel::INFO      => log::INFO,
        LogLevel::DEBUG     => log::DEBUG,
    ];

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Write at an arbitrary level.
     *
     * @param mixed                $level   One of the Psr\Log\LogLevel constants
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     *
     * @return void
     * @throws InvalidArgumentException When the level is not one of the eight PSR-3 levels
     */
    public function log($level, $message, array $context = []): void
    {
        $name = is_string($level) ? strtolower($level) : '';

        if ( !isset(self::LEVELS[$name]) )
        {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a PSR-3 log level',
                is_scalar($level) ? (string) $level : gettype($level)
            ));
        }

        log::write(self::LEVELS[$name], (string) $message, $context);
    }
}
