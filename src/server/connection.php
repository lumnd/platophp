<?php

/**
 * One client connection, as the application sees it
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\server;

/**
 * A client, without the driver's socket object attached to it.
 *
 * Application code -- a controller reached over a socket, an open hook, a broadcast helper --
 * gets this and never the adapter's own connection class. That is the whole point of it: a
 * controller written against `Workerman\Connection\TcpConnection` cannot be tested without booting
 * Workerman, and cannot be moved to another adapter at all. Everything here is either a plain value
 * or a call the driver answers, so a fake driver in a test is a class with an array in it.
 *
 * Two things it carries beyond the id:
 *
 *   attributes   per connection state, **of this process only**. A long lived connection lives
 *                across many messages, so this is where what was established once belongs -- who
 *                the client is, which room it joined, when it last spoke. It is not shared with the
 *                other workers and it dies with the connection; anything that has to outlive either
 *                goes to a cache or a database like it would in a request.
 *   the identity  under the AUTH key. `dispatcher` copies it into `plato::$auth` before every
 *                dispatch and takes it back out afterwards, which is what lets a controller ask
 *                who the caller is the same way it would over http. Nothing else in the framework
 *                treats an attribute specially.
 *
 * The driver builds one of these per connection and **keeps the instance**: the attributes are only
 * worth having if the same object comes back on the next message.
 */
class connection
{
    /**
     * Attribute the authenticated identity is stored under, see the class docblock.
     */
    public const AUTH = 'auth';

    /**
     * Id the driver knows this connection by, unique within its process.
     *
     * @var string
     */
    private $_id;

    /**
     * Driver object holding the socket.
     *
     * @var driver
     */
    private $_driver;

    /**
     * Peer address as the driver reports it, 'ip:port' or '' when it does not.
     *
     * @var string
     */
    private $_remote;

    /**
     * When the connection was accepted, as a float unix time.
     *
     * @var float
     */
    private $_opened_at;

    /**
     * Per connection state, see the class docblock.
     *
     * @var array<string, mixed>
     */
    private $_attributes = [];

    /**
     * Whether the socket is still open as far as this object knows.
     *
     * @var bool
     */
    private $_open = true;

    /**
     * @param string     $id        Driver's id for the connection
     * @param driver     $driver    Driver holding the connection
     * @param string     $remote    Peer address, 'ip:port'
     * @param float|null $opened_at Accept time; now when omitted
     */
    public function __construct(string $id, driver $driver, string $remote = '', ?float $opened_at = null)
    {
        $this->_id        = $id;
        $this->_driver    = $driver;
        $this->_remote    = $remote;
        $this->_opened_at = $opened_at ?? microtime(true);
    }

    /**
     * Id the driver knows this connection by.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->_id;
    }

    /**
     * Driver object holding the socket.
     *
     * @return driver
     */
    public function driver(): driver
    {
        return $this->_driver;
    }

    /**
     * Peer address, 'ip:port', or '' when the driver does not report one.
     *
     * Behind a reverse proxy this is the proxy. A client address that matters comes out of the
     * handshake headers, which the driver hands to the open hook, and belongs in an attribute.
     *
     * @return string
     */
    public function remote(): string
    {
        return $this->_remote;
    }

    /**
     * When the connection was accepted, as a float unix time.
     *
     * @return float
     */
    public function opened_at(): float
    {
        return $this->_opened_at;
    }

    /**
     * How long the connection has been open, in seconds.
     *
     * @return float
     */
    public function age(): float
    {
        return microtime(true) - $this->_opened_at;
    }

    /**
     * Store a per connection value.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function set(string $key, $value): self
    {
        $this->_attributes[$key] = $value;

        return $this;
    }

    /**
     * Read a per connection value.
     *
     * @param string $key
     * @param mixed  $default Returned when the key was never set
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->_attributes) ? $this->_attributes[$key] : $default;
    }

    /**
     * Whether a key is set, telling a stored null apart from a missing key.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->_attributes);
    }

    /**
     * Drop a per connection value.
     *
     * @param string $key
     *
     * @return $this
     */
    public function forget(string $key): self
    {
        unset($this->_attributes[$key]);

        return $this;
    }

    /**
     * Every per connection value.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->_attributes;
    }

    /**
     * Send a payload to this client.
     *
     * A string goes out as it is; anything else is encoded by the dispatcher's codec first, so the
     * wire format has one owner and a caller does not have to remember which one is configured.
     *
     * @param mixed $payload String, or anything the codec accepts
     *
     * @return bool  False when the write failed or the connection is already gone
     */
    public function send($payload): bool
    {
        if ( !$this->_open )
        {
            return false;
        }

        return $this->_driver->send($this->_id, is_string($payload) ? $payload : dispatcher::encode($payload));
    }

    /**
     * Close this connection.
     *
     * @param int    $code   Close code as the driver's protocol defines it, see driver::close()
     * @param string $reason Short text; a driver that cannot carry it drops it
     *
     * @return bool
     */
    public function close(int $code = 1000, string $reason = ''): bool
    {
        if ( !$this->_open )
        {
            return false;
        }

        // Marked closed before the call, so a driver that answers the close by calling back into
        // dispatcher::close() -- most do -- does not send anything on the way out
        $this->_open = false;

        return $this->_driver->close($this->_id, $code, $reason);
    }

    /**
     * Whether the socket is still open as far as this object knows.
     *
     * @return bool
     */
    public function is_open(): bool
    {
        return $this->_open;
    }

    /**
     * Record that the socket is gone, for the driver to call when the peer closed it.
     *
     * @return void
     */
    public function mark_closed(): void
    {
        $this->_open = false;
    }
}
