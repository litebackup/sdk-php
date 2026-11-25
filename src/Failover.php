<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;
use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\EventList;
use Litebackup\Generated\EventType;
use Litebackup\Generated\Failover as FailoverStatus;
use Litebackup\Generated\StopBackupResult;
use Litebackup\Generated\Writer;

/**
 * A failover started on this machine. PHP has no threads, so nothing
 * follows it in the background: follow() reads the daemon's events in the
 * calling process and runs the callbacks there, in event order, and only
 * there. The first call of follow() first passes onRoleChange the role the
 * start answered.
 *
 * Each replica asks for the write lock about once a second, so one takes
 * over once the origin's lease runs out. role() is origin, replica, or
 * unknown: unknown while the daemon does not answer, once the failover
 * stopped, from a draining or denied event until the next role change or a
 * role read past it, and whenever the daemon has not confirmed the role for
 * 15 seconds, which outside follow() it never does. So a process that does
 * not follow never reports a stale origin; one that writes only while
 * isOrigin() holds stops writing 15 seconds after its last confirmation at
 * the latest. The SDK sets unknown; the daemon never reports it.
 */
final class Failover
{
    public const ORIGIN = DatabaseStatus::ROLE_ORIGIN;
    public const REPLICA = DatabaseStatus::ROLE_REPLICA;
    public const UNKNOWN = 'unknown';
    private const EVENT_PAGE = 1000;
    private const ROLE_EVENT_TYPES = [EventType::ROLE_CHANGED, EventType::DRAINING, EventType::DENIED];
    /**
     * Error codes that end following instead of being retried. An expired
     * cursor is not one: a failover resumes at the log's end.
     */
    private const FATAL_ERROR_CODES = [
        ErrorCode::SDK_UNSUPPORTED,
        ErrorCode::CLOSED,
        ErrorCode::CANCELLED,
        ErrorCode::INVALID_ARGUMENT,
    ];
    /** The least time a long-poll is worth: with less of follow()'s time left, follow() returns. */
    private const SHORTEST_LONG_POLL_SECONDS = 0.5;
    /** How long before follow()'s end a long-poll asks the daemon to answer, so the answer arrives in time. */
    private const LONG_POLL_MARGIN_SECONDS = 0.25;

    /**
     * How long one failover long-poll waits. It is shorter than
     * confirmationLimitSeconds, so a daemon that answers confirms the role in
     * time.
     */
    public static float $eventWaitSeconds = 10.0;
    /**
     * The longest a failover reports a role the daemon has not confirmed.
     * 15 s is the default client heartbeat interval and a quarter of the
     * default 60 s session lease another machine waits out before it takes
     * over. Tests shorten it.
     */
    public static float $confirmationLimitSeconds = 15.0;

    public readonly string $name;
    public readonly string $path;
    private string $role;
    private bool $stopped = false;
    private bool $startRoleReported = false;
    private bool $following = false;
    private readonly int $ownerPID;
    private readonly float $eventWait;
    private readonly float $confirmationLimit;
    /** The newest event ID handed to the failover. */
    private int $cursor;
    /** The backoff after the next failed read. */
    private float $delay = Wire::FIRST_RETRY_SECONDS;
    /** When the daemon last confirmed the role, on the monotonic clock. */
    private float $confirmed;
    /** Whether the role is read before the next long-poll. */
    private bool $roleReadPending = false;
    /** Whether the log's end is read before that role read. */
    private bool $logEndPending = false;
    /**
     * The log's end read before the last role read, whose role_changed
     * events are older than the read and skipped.
     */
    private int $roleReadLogEnd = 0;
    /** When the current follow() returns, or null for no end. */
    private ?float $followDeadline = null;

    /**
     * @internal Start failovers with $client->failovers->start().
     * @param (Closure(string): void)|null $onRoleChange
     * @param (Closure(string): void)|null $onDenied
     * @param (Closure(LitebackupError): void)|null $onError
     */
    public function __construct(
        private readonly Client $client,
        public readonly FailoverStatus $status,
        int $cursor,
        private readonly ?Closure $onRoleChange = null,
        private readonly ?Closure $onDenied = null,
        private readonly ?Closure $onError = null,
    ) {
        $this->name = $status->name;
        $this->path = $status->path;
        $this->role = $status->role;
        $this->cursor = $cursor;
        $this->ownerPID = getmypid() ?: 0;
        $this->eventWait = self::$eventWaitSeconds;
        $this->confirmationLimit = self::$confirmationLimitSeconds;
        $this->confirmed = Clock::now();
    }

    /**
     * The role this machine has, as the daemon last confirmed it within the
     * confirmation limit; unknown after that, once stopped, and in a forked
     * child.
     */
    public function role(): string
    {
        if ($this->ownerPID !== getmypid() || $this->stopped) {
            return self::UNKNOWN;
        }
        if ($this->role !== self::UNKNOWN && Clock::now() - $this->confirmed >= $this->confirmationLimit) {
            return self::UNKNOWN;
        }
        return $this->role;
    }

    /** Whether this machine writes the database now; false while the role is unknown. */
    public function isOrigin(): bool
    {
        return $this->role() === self::ORIGIN;
    }

    /**
     * Whether following ended: after stop(), after the client closed, and
     * after sdk_unsupported or another error that ends following, when the
     * failover still runs on the daemon.
     */
    public function stopped(): bool
    {
        return $this->stopped;
    }

    /** Which machine writes the database, when its lease runs out, and whether it ran out. */
    public function writer(): Writer
    {
        $this->ensureOwner();
        return $this->client->databases->writer($this->name);
    }

    /**
     * Stops the failover and releases its write lock. The file stays. Once
     * it returns no callback runs, and a follow() that called it from a
     * callback returns after that callback.
     */
    public function stop(): StopBackupResult
    {
        $this->ensureOwner();
        $this->markStopped();
        return $this->client->backups->stop($this->name);
    }

    /**
     * Follows the failover's events in this process until seconds pass,
     * stop() is called from a callback, or following ends (sdk_unsupported,
     * or the client closed); with null seconds, until one of the last two.
     * Callbacks run inside it. A failed read is retried with backoff, and
     * onError hears it. A follow() that starts after more than the
     * confirmation limit without one reads the log's end and the role, as
     * after an unknown role, before it trusts origin.
     *
     * @param int|float|null $seconds
     */
    public function follow(mixed $seconds = null): void
    {
        $this->ensureOwner();
        if ($this->following) {
            throw LitebackupError::invalidArgument('follow() is already running for ' . $this->name);
        }
        $followSeconds = Wire::seconds($seconds, 'the follow time');
        if ($seconds !== null && $followSeconds === null) {
            return;
        }
        $this->followDeadline = $followSeconds === null ? null : Clock::now() + $followSeconds;
        $this->following = true;
        try {
            if (!$this->startRoleReported) {
                $this->startRoleReported = true;
                $this->reportStartRole();
            }
            while (!$this->stopped && $this->followTimeLeft() > 0) {
                try {
                    if (!$this->readDaemon()) {
                        return;
                    }
                } catch (FollowTimeSpent) {
                    return;
                } catch (LitebackupError $error) {
                    $wait = $this->retryDelay($error);
                    if ($wait === null) {
                        return;
                    }
                    Clock::sleep(min($wait, $this->followTimeLeft()));
                    continue;
                }
                $this->delay = Wire::FIRST_RETRY_SECONDS;
            }
        } finally {
            $this->following = false;
            $this->followDeadline = null;
        }
    }

    /**
     * Makes the role unknown without a callback, as a closing client does.
     *
     * @internal
     */
    public function clientClosed(): void
    {
        $this->markStopped();
    }

    private function ensureOwner(): void
    {
        if ($this->ownerPID === getmypid()) {
            return;
        }
        throw LitebackupError::invalidArgument(
            'this failover was started in process ' . $this->ownerPID . ' and is not followed after a fork; '
            . 'start it again in this process',
        );
    }

    /** Seconds left of follow(), or INF without an end. */
    private function followTimeLeft(): float
    {
        return $this->followDeadline === null ? INF : max(0.0, $this->followDeadline - Clock::now());
    }

    private function roleEvent(string $eventType): bool
    {
        return in_array($eventType, self::ROLE_EVENT_TYPES, true);
    }

    /**
     * The role a status read reports: unknown while the daemon drains the
     * origin, as after a draining event, and while the failover's file is
     * refused (conflicted), as after a denied event.
     */
    private function statusRole(DatabaseStatus $database): string
    {
        return $database->draining === true || $database->state === DatabaseStatus::STATE_CONFLICTED
            ? self::UNKNOWN
            : $database->role;
    }

    private function markStopped(): void
    {
        $this->stopped = true;
        $this->role = self::UNKNOWN;
        $this->client->removeFailover($this);
    }

    /**
     * Calls onRoleChange with the role the start answered, so an app can
     * route writes from its callback alone; with unknown instead when the
     * daemon has not confirmed that role within the confirmation limit,
     * and the role is then read before origin is trusted.
     */
    private function reportStartRole(): void
    {
        if ($this->role !== self::UNKNOWN && Clock::now() - $this->confirmed >= $this->confirmationLimit) {
            $this->role = self::UNKNOWN;
            $this->roleReadPending = true;
            $this->logEndPending = true;
        }
        if (!$this->stopped && $this->onRoleChange !== null) {
            ($this->onRoleChange)($this->role);
        }
    }

    /** Saves a role and calls onRoleChange when it differs; returns whether it changed. */
    private function changeRole(?string $newRole): bool
    {
        if ($this->stopped || !Wire::supplied($newRole) || $newRole === $this->role) {
            return false;
        }
        /** @var string $newRole */
        $this->role = $newRole;
        if ($this->onRoleChange !== null) {
            ($this->onRoleChange)($newRole);
        }
        return true;
    }

    private function reportError(LitebackupError $error): void
    {
        if ($this->onError !== null && !$this->stopped) {
            ($this->onError)($error);
        }
    }

    private function applyEvent(\Litebackup\Generated\Event $event): void
    {
        if ($this->stopped || $event->name !== $this->name) {
            return;
        }
        // An event older than the last role read changes no role, because
        // that read has it; a refusal among them is still reported.
        $olderThanRoleRead = $this->roleEvent($event->type) && $event->id <= $this->roleReadLogEnd;
        if ($olderThanRoleRead && $event->type !== EventType::DENIED) {
            return;
        }
        switch ($event->type) {
            case EventType::ROLE_CHANGED:
                $this->changeRole(Wire::supplied($event->role) ? $event->role : $event->message);
                break;
            case EventType::DRAINING:
                // The daemon drains: it stops being the origin before it
                // releases the lock, so the app stops writing while no
                // machine is allowed to take over yet.
                $this->changeRole(self::UNKNOWN);
                break;
            case EventType::DENIED:
                // The daemon refused this machine's file and the failover
                // stays conflicted there: this machine neither writes nor
                // follows the database.
                if (!$olderThanRoleRead) {
                    $this->changeRole(self::UNKNOWN);
                }
                // The role callback could have stopped the failover.
                if (!$this->stopped() && $this->onDenied !== null) {
                    ($this->onDenied)($event->message);
                }
                break;
        }
    }

    /**
     * Asks for a role read after a long-poll that brought no role change
     * while the role is unknown. A drain that failed says nothing more after
     * its draining event; its status shows the role once it stopped.
     */
    private function readUnknownRoleAgain(EventList $listing): void
    {
        if ($this->role !== self::UNKNOWN) {
            return;
        }
        foreach ($listing->events as $event) {
            if ($event->name === $this->name && $this->roleEvent($event->type) && $event->id > $this->roleReadLogEnd) {
                return;
            }
        }
        $this->roleReadPending = true;
        $this->logEndPending = true;
    }

    /**
     * Moves roleReadLogEnd through a page read after it, up to logEnd, and
     * returns whether the page changed this database's role.
     */
    private function scanRoleChangePage(EventList $listing, int $logEnd): bool
    {
        $superseded = false;
        foreach ($listing->events as $event) {
            if ($event->id > $logEnd) {
                break;
            }
            if ($event->name === $this->name && $this->roleEvent($event->type)) {
                $superseded = true;
            }
        }
        if ($listing->events !== []) {
            // Never past logEnd: a role change after it came after the role
            // read, and the long-poll applies it.
            $last = $listing->events[count($listing->events) - 1]->id;
            $this->roleReadLogEnd = max($this->roleReadLogEnd, min($last, $logEnd));
        }
        return $superseded;
    }

    /** Makes the role unknown and, when that changed it, has the follower read the log's end and the role. */
    private function loseRole(): void
    {
        if (!$this->changeRole(self::UNKNOWN)) {
            return;
        }
        $this->roleReadPending = true;
        $this->logEndPending = true;
    }

    /** Makes the role unknown once the daemon has not confirmed it for the confirmation limit. */
    private function loseUnconfirmedRole(): void
    {
        if ($this->role !== self::UNKNOWN && Clock::now() - $this->confirmed >= $this->confirmationLimit) {
            $this->loseRole();
        }
    }

    /**
     * Sends a follower read with a deadline of the confirmation limit after
     * the last confirmation, or after now once the role is unknown, and no
     * later than follow()'s end. A read that follow()'s end cut short throws
     * FollowTimeSpent, which ends follow() and changes nothing.
     *
     * @template T
     * @param Call<T> $call
     * @return T
     */
    private function send(Call $call): mixed
    {
        $now = Clock::now();
        $start = $this->role === self::UNKNOWN ? $now : $this->confirmed;
        $confirmationLeft = max(0.0, $start + $this->confirmationLimit - $now);
        $followLeft = $this->followTimeLeft();
        try {
            return $this->client->call($call->withAnswerDeadline(min($confirmationLeft, $followLeft)));
        } catch (LitebackupError $error) {
            if ($error->errorCode === ErrorCode::TIMEOUT && $error->status === 0 && $followLeft < $confirmationLeft) {
                throw new FollowTimeSpent();
            }
            throw $error;
        }
    }

    /**
     * After a failed read: makes the role unknown when the daemon did not
     * answer or following ends, reports the error, and returns how long to
     * wait before the next read, or null when following ends. While the role
     * is known, the wait ends when the confirmation limit passes.
     */
    private function retryDelay(LitebackupError $error): ?float
    {
        if ($this->stopped) {
            return null;
        }
        $ending = in_array($error->errorCode, self::FATAL_ERROR_CODES, true);
        if ($error->errorCode === ErrorCode::CLOSED) {
            // The client is closing: no role callback.
            $this->role = self::UNKNOWN;
        } elseif ($ending || $error->status === 0) {
            $this->loseRole();
        }
        $this->reportError($error);
        if ($ending) {
            // Following ends for good: later follow() calls return at once,
            // and stopped() says so. The failover keeps running on the daemon.
            $this->stopped = true;
            $this->role = self::UNKNOWN;
            $this->client->removeFailover($this);
            return null;
        }
        if ($this->stopped) {
            return null;
        }
        $wait = $this->delay;
        if ($this->role !== self::UNKNOWN) {
            $wait = max(0.0, min($wait, $this->confirmed + $this->confirmationLimit - Clock::now()));
        }
        $this->delay = min($this->delay * 2, Wire::MAXIMUM_RETRY_SECONDS);
        return $wait;
    }

    /**
     * The follower's next read: the log's end and the role when a role read
     * is pending, one long-poll otherwise. Returns false when too little of
     * follow()'s time is left for a long-poll.
     *
     * An expired cursor does not end following: it moves to the log's end,
     * then the role is read again before the next long-poll, so a role
     * change in events the daemon pruned is still reported.
     */
    private function readDaemon(): bool
    {
        $this->loseUnconfirmedRole();
        if ($this->roleReadPending) {
            $this->readRole();
            return true;
        }
        $followLeft = $this->followTimeLeft();
        if ($followLeft < self::SHORTEST_LONG_POLL_SECONDS) {
            return false;
        }
        $wait = min($this->eventWait, $followLeft - self::LONG_POLL_MARGIN_SECONDS);
        try {
            $listing = $this->send(Wire::eventsPage($this->cursor, $wait, self::EVENT_PAGE));
        } catch (LitebackupError $error) {
            if ($this->stopped || $error->errorCode !== ErrorCode::CURSOR_EXPIRED) {
                throw $error;
            }
            $this->reportError($error);
            if ($this->stopped) {
                return true;
            }
            $logEnd = $this->send(Wire::eventsPage(-1))->next;
            $this->cursor = $logEnd;
            $this->roleReadLogEnd = $logEnd;
            $this->roleReadPending = true;
            $this->logEndPending = false;
            return true;
        }
        $this->confirmed = Clock::now();
        foreach ($listing->events as $event) {
            $this->cursor = max($this->cursor, $event->id);
            $this->applyEvent($event);
        }
        $this->cursor = max($this->cursor, $listing->next);
        $this->readUnknownRoleAgain($listing);
        return true;
    }

    private function readRole(): void
    {
        if ($this->logEndPending) {
            $this->roleReadLogEnd = $this->send(Wire::eventsPage(-1))->next;
            $this->logEndPending = false;
        }
        $read = $this->statusRole($this->send(Wire::database($this->name)));
        // The role read could predate a role change the log now has: read
        // the role again, then look past the new log end.
        if ($this->roleSuperseded()) {
            return;
        }
        $this->confirmed = Clock::now();
        $this->roleReadPending = false;
        $this->changeRole($read);
    }

    /**
     * Reads the log's end as of now, then the events from roleReadLogEnd
     * through it, without waiting. Returns whether one changed this
     * database's role, so a role read that raced it is not trusted.
     * roleReadLogEnd moves to that end; events after it came after the role
     * read. When pruning passed roleReadLogEnd, it returns true with
     * logEndPending set, so the follower starts again from a fresh log end.
     */
    private function roleSuperseded(): bool
    {
        $logEnd = $this->send(Wire::eventsPage(-1))->next;
        $superseded = false;
        while ($this->roleReadLogEnd < $logEnd) {
            try {
                $listing = $this->send(Wire::eventsPage($this->roleReadLogEnd, null, self::EVENT_PAGE));
            } catch (LitebackupError $error) {
                if ($error->errorCode !== ErrorCode::CURSOR_EXPIRED) {
                    throw $error;
                }
                $this->logEndPending = true;
                return true;
            }
            $superseded = $this->scanRoleChangePage($listing, $logEnd) || $superseded;
            if ($listing->events === []) {
                break;
            }
        }
        $this->roleReadLogEnd = max($this->roleReadLogEnd, $logEnd);
        return $superseded;
    }
}
