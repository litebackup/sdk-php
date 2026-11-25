<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\ShutdownResult;
use Litebackup\Generated\Status;

/** The session and its daemon. */
final class Session extends Group
{
    /**
     * @param string $after
     * @param int $limit
     */
    public function status(mixed $after = '', mixed $limit = 100): Status
    {
        return $this->client->call(Wire::status($after, $limit));
    }

    /**
     * Final-syncs every database, releases every write lock, and stops the
     * daemon. A backup another machine writes has no lock and stays waiting.
     * Each database it could not sync or release is in failed, with its
     * code, and keeps any write lock it has; the session and the daemon then
     * stay. An empty failed is the only complete shutdown. Client::close()
     * never does this.
     *
     * @param string|null $durability
     * @param int|float|null $timeout seconds
     */
    public function shutdown(mixed $durability = null, mixed $timeout = null): ShutdownResult
    {
        return $this->client->call(Wire::shutdown($durability, $timeout));
    }
}
