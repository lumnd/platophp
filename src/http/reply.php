<?php

/**
 * Server response value returned by controller actions and middleware
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\http;

use Closure;

/**
 * An HTTP response before it is emitted by resp::send().
 *
 * The body may be a string or a writer used for files and streams. Reading body() captures a writer
 * once, which lets non-HTTP adapters such as the server dispatcher consume the same value.
 */
class reply
{
    private int $_status;

    /** @var array<string, string> */
    private array $_headers;

    /** @var string|Closure */
    private $_content;

    private ?string $_rendered = null;

    /**
     * @param int                   $status
     * @param array<string, string> $headers
     * @param string|Closure        $content
     */
    public function __construct(int $status = 200, array $headers = [], $content = '')
    {
        $this->_status  = $status;
        $this->_headers = $headers;
        $this->_content = $content;
    }

    public function status(): int
    {
        return $this->_status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->_headers;
    }

    /**
     * Return a copy with one response header added or replaced.
     */
    public function with_header(string $name, string $value): self
    {
        $copy = clone $this;
        $name = str_replace(["\r", "\n"], '', trim($name));

        if ( $name !== '' )
        {
            $copy->_headers[$name] = str_replace(["\r", "\n"], '', $value);
        }

        return $copy;
    }

    /**
     * Return a copy carrying a different body.
     *
     * For a middleware that rewrites what the action produced -- appending a debug panel, minifying,
     * rewriting urls. The copy holds a string, so a writer this reply was built with is dropped
     * rather than wrapped: whoever replaces the body of a file or stream response has already read
     * it through body() and buffered it, and keeping the writer too would emit the old one as well.
     */
    public function with_body(string $body): self
    {
        $copy = clone $this;
        $copy->_content  = $body;
        $copy->_rendered = null;

        return $copy;
    }

    /**
     * Return a copy with a different status code.
     */
    public function with_status(int $status): self
    {
        $copy = clone $this;
        $copy->_status = $status;

        return $copy;
    }

    public function body(): string
    {
        if ( is_string($this->_content) )
        {
            return $this->_content;
        }

        if ( $this->_rendered === null )
        {
            ob_start();

            try
            {
                ($this->_content)();
            }
            finally
            {
                $this->_rendered = (string) ob_get_clean();
            }
        }

        return $this->_rendered;
    }

    /**
     * Write the body without buffering a stream or file into memory.
     */
    public function emit_body(): void
    {
        if ( is_string($this->_content) )
        {
            echo $this->_content;
            return;
        }

        if ( $this->_rendered !== null )
        {
            echo $this->_rendered;
            return;
        }

        ($this->_content)();
    }
}
