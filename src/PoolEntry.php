<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * One database of a pool.
 *
 * @internal
 */
final class PoolEntry
{
    /** The open handle, while opened is set. */
    public ?object $handle = null;
    public string $path = '';
    public bool $opened = false;
    /** Set while this pool has the write lock through lease. */
    public bool $leased = false;
    public ?LeaseSettings $leaseSettings = null;
    /** Keeps the release from offloading after a call that did not finish. */
    public bool $lastCallFailed = false;
    /**
     * Set from before a lease call's work starts until its work and
     * durability wait finished, so the shutdown function can tell a call
     * that exit or a fatal error ended.
     */
    public bool $callUnfinished = false;
    /** Seconds a rule's database stays open unused, null never closing it. */
    public ?float $idleClose = null;
    /**
     * Set for a rollback-journal database whose rule offloads
     * rollback-journal databases, which the pool closes as soon as the last
     * use call returns.
     */
    public bool $closeAfterUse = false;
    /** How many calls use the entry, nested calls included. */
    public int $users = 0;
    public ?float $lastUsed = null;

    public function __construct(public readonly string $name) {}

    /** The handle, which the entry no longer holds, for an adapter's close. */
    public function detachHandle(): object
    {
        $handle = $this->handle;
        if ($handle === null) {
            throw new \LogicException($this->name . ' has no open handle');
        }
        $this->handle = null;
        return $handle;
    }
}
