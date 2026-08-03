<?php

/**
 * Optional driver contract: holding a message back until it is due
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\queue;

/**
 * Delayed delivery, for the drivers that can do it.
 *
 * Redis has a sorted set, which is a schedule; kafka has a log, which is not, and no amount of
 * wrapping turns one into the other. So this is a separate interface rather than two more methods
 * on driver, and the facade refuses a delayed push on a driver that does not implement it instead
 * of quietly delivering the message straight away.
 *
 * A delayed message is not on the queue a consumer reads. Something has to move it across once it
 * comes due, and that something is migrate_delayed(). The worker calls it on the way into each
 * blocking read, which is why no separate scheduler process is needed:
 *
 *  - the move is one lua script, so several workers running it at once cannot deliver a message
 *    twice -- the worst that happens is that all but one get an empty result;
 *  - the script reports when the next message comes due, and the worker both skips the call until
 *    then and shortens its blocking read to land on it, so accuracy is a function of the
 *    configured migrate interval rather than of how often somebody remembered to run a cron job;
 *  - nothing migrates while no worker runs, which is correct: nothing is consuming either, and
 *    the backlog moves across on the first read after a worker comes up.
 *
 * The method stays public so a deployment that would rather have a dedicated process can still
 * have one, without any of the above changing.
 */
interface delayable
{
    /**
     * Push a message to be delivered $delay seconds from now.
     *
     * @param string               $queue   Queue name
     * @param mixed                $data    Payload; anything json_encode() accepts
     * @param int                  $delay   Seconds to hold it back; 0 or less pushes immediately
     * @param array<string, mixed> $options Driver specific extras
     *
     * @return string|null  The message id, null when the backend refused it
     */
    public function push_delay(string $queue, $data, int $delay, array $options = []);

    /**
     * Move every message that has come due onto the queue a consumer reads.
     *
     * Has to be atomic per message: a move that reads, writes and then deletes loses whatever was
     * in flight when the process died, and a queue that loses messages when a worker is restarted
     * is not a queue.
     *
     * @param string|array<int, string> $queues Queue name, or several
     * @param int                       $limit  Most messages to move in one call
     *
     * @return array{0:int,1:int}  Messages moved, and the unix time the next one comes due --
     *                             0 when nothing is waiting, which lets the caller stop asking
     */
    public function migrate_delayed($queues, int $limit = 128): array;
}
