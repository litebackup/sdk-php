<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;

/** Databases run on several identical machines. */
final class Failovers extends Group
{
    /**
     * Runs the database at path, the same on every machine; returns once
     * this machine is the origin or has a replica. Call follow() on the
     * Failover to hear its role changes: onRoleChange hears the role the
     * start answered, then every change; onRefused hears the daemon's
     * message when it refused this machine's file; onError hears each failed
     * read. A file at the path that differs from the server head, and is
     * not a clean copy of the version this machine last synced, is refused
     * with conflict and left untouched; discardLocal replaces it with the head.
     *
     * @param string|\Stringable|\SplFileInfo $path
     * @param string|null $name
     * @param int|float|null $timeout seconds
     * @param bool $discardLocal
     * @param (Closure(string): void)|null $onRoleChange
     * @param (Closure(string): void)|null $onRefused
     * @param (Closure(LitebackupError): void)|null $onError
     */
    public function start(
        mixed $path,
        mixed $name = null,
        mixed $timeout = null,
        mixed $discardLocal = false,
        ?Closure $onRoleChange = null,
        ?Closure $onRefused = null,
        ?Closure $onError = null,
    ): Failover {
        $call = Wire::startFailover($path, $name, $timeout, $discardLocal);
        $logEnd = $this->client->call(Wire::eventsPage(-1));
        $started = $this->client->call($call);
        $this->client->ensureOpen();
        $failover = new Failover($this->client, $started, $logEnd->next, $onRoleChange, $onRefused, $onError);
        $this->client->addFailover($failover);
        return $failover;
    }
}
