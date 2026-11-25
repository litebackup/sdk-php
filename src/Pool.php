<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;
use Litebackup\Generated\BackupRule;
use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\Durability;
use Throwable;
use WeakReference;

/**
 * Keeps databases open for calls in this PHP process. Create it with
 * $client->databases->pool():
 *
 *     $pool = $litebackup->databases->pool(Litebackup\Integrations\PdoSqlite::adapter());
 *     $pool->lease('agents/42.sqlite', function (PDO $db): void {
 *         $db->prepare('INSERT INTO steps (body) VALUES (?)')->execute(['tests passed']);
 *     }, path: 'data/agents/42.sqlite');
 *     $pool->close();
 *
 * use is for a database a backup rule on this machine covers: it stays here,
 * open between calls. lease is for a database that moves between machines:
 * the first lease acquires the write lock, and the pool keeps it, with the
 * open handle, between calls until idleRelease runs out (a minute by
 * default), release, or close. The app coordinates handovers: another
 * machine's lease with lockTimeout succeeds once this one released the
 * database. A lease on a database whose lock was lost meanwhile fails with
 * lock_lost before its work, and the next lease acquires again. A close that
 * fails never releases: a connection may still be open, and another machine
 * must not write while this one still can.
 *
 * PHP has no threads, so the idle close and the idle release are
 * cooperative: they run at the start of each call and in close(), and an
 * idle worker that makes no call keeps its lease until it calls, closes, or
 * exits. Under PHP-FPM a pool lives as long as its request. A shutdown
 * function closes the pool when the PHP process ends without close(), as
 * after exit or a fatal error, which skip finally: a lease whose call never
 * finished is released without offload. SIGTERM and SIGKILL run no shutdown
 * function, and the daemon keeps renewing its session, so a lease a killed
 * process held stays acquired until litebackup release NAME, a release from
 * another process, or a session shutdown ends it. After an execution-time
 * fatal error PHP runs the shutdown function with the work's frame alive, so
 * a PDO connection it holds makes the close fail, and the lease stays
 * acquired. A pool made before a fork is refused in the child.
 */
final class Pool
{
    /**
     * Seconds a database whose backup rule offloads (maximum_local_databases)
     * stays open unused before the pool closes it, so the rule offloads it,
     * unless idleClose says otherwise. It is well inside the client's
     * 10-minute idle window.
     */
    public const DEFAULT_OFFLOAD_IDLE_CLOSE = 120.0;
    /** How often a lease with cloud durability asks whether the bucket has its version yet. */
    public const CLOUD_DURABILITY_POLL_SECONDS = 1.0;
    /**
     * A release timeout of zero means the daemon's default, so a spent
     * cleanup timeout sends the smallest one instead.
     */
    private const MINIMUM_RELEASE_SECONDS = 0.001;

    /** How long a database whose rule offloads stays open unused; tests shorten it. */
    public static float $offloadIdleCloseSeconds = self::DEFAULT_OFFLOAD_IDLE_CLOSE;
    /** How long a lease with cloud durability waits between status reads; tests shorten it. */
    public static float $cloudPollSeconds = self::CLOUD_DURABILITY_POLL_SECONDS;

    /** @var array<string, PoolEntry> */
    private array $entries = [];
    /** @var array<string, BackupRule> the rules read, by ID */
    private array $rules = [];
    /** How many calls are running, nested ones included. */
    private int $activeCalls = 0;
    private bool $closing = false;
    private readonly int $ownerPID;
    /** @var list<LeaseCleanupError> release failures found by the idle pass, reported once the call is done */
    private array $releaseFailures = [];

    /** @internal Create pools with $client->databases->pool(). */
    public function __construct(
        private readonly Client $client,
        private readonly Adapter $adapter,
        private readonly PoolSettings $settings,
    ) {
        $this->ownerPID = getmypid() ?: 0;
        $pool = WeakReference::create($this);
        register_shutdown_function(static function () use ($pool): void {
            $pool->get()?->closeOnShutdown();
        });
    }

    /**
     * Calls work with the open handle of a database a backup rule on this
     * machine covers, and returns what work returns.
     *
     * The pool opens it on first use and keeps it open; an offloaded copy is
     * brought back first with acquire, never recreated empty. A
     * rollback-journal database under a rule with offloadRollbackJournal is
     * closed as soon as its last use returns instead, because that rule
     * offloads it while nothing has it open. A name no rule on this machine
     * covers, or an acquired database, is refused with invalid_argument;
     * lease it instead.
     *
     * @template R
     * @param string $name
     * @param Closure(object): R $work
     * @return R
     */
    public function use(mixed $name, Closure $work): mixed
    {
        $entry = $this->join($name);
        try {
            if ($entry->leased) {
                throw LitebackupError::invalidArgument($entry->name . ' is leased in this pool; use lease for it');
            }
            if (!$entry->opened) {
                $this->openRuleDatabase($entry);
            }
            /** @var object $handle */
            $handle = $entry->handle;
            return $work($handle);
        } finally {
            unset($handle);
            $this->leave($entry);
            if ($entry->closeAfterUse) {
                $this->closeAfterLastUse($entry);
            }
            $this->reportReleaseFailures();
        }
    }

    /**
     * Calls work with the open handle of a database that moves between
     * machines, and returns what work returns.
     *
     * No other machine writes while the pool keeps the lock. The first
     * lease acquires the write lock, creating an empty file and its
     * directory at path when nothing is there unless requireExisting, and
     * opens it; later leases reuse the lock and the handle. lockTimeout is
     * how long that acquire waits while another machine has the write lock;
     * without it the lease fails at once with lock_unavailable.
     * acquireTimeout limits downloading the head; maintenance checkpoints on
     * release and VACUUMs past the freelist threshold.
     *
     * A later lease first asks the daemon whether this machine still has
     * the lock: when it lost it, the handle is closed, the lease dropped,
     * and lock_lost thrown without calling work; the next lease acquires
     * again.
     *
     * With durability, work that returns syncs and returns only once the
     * server, or the bucket with cloud, has what it committed; without it,
     * the writes sync in the background. The release that ends the lease
     * waits for the same durability, the server when null, and with offload
     * removes the local file, unless the last call did not finish: work that
     * threw, or a durability wait that failed, keeps the lease and its local
     * copy, and never offloads. The adapters never commit for work: commit
     * what it should keep.
     *
     * @template R
     * @param string $name
     * @param Closure(object): R $work
     * @param string|\Stringable|\SplFileInfo|null $path
     * @param bool $requireExisting
     * @param string|null $durability Durability::SERVER or Durability::CLOUD
     * @param bool $offload
     * @param int|float|null $lockTimeout seconds
     * @param int|float|null $acquireTimeout seconds
     * @param bool $maintenance
     * @return R
     */
    public function lease(
        mixed $name,
        Closure $work,
        mixed $path = null,
        mixed $requireExisting = false,
        mixed $durability = null,
        mixed $offload = false,
        mixed $lockTimeout = null,
        mixed $acquireTimeout = null,
        mixed $maintenance = false,
    ): mixed {
        $leaseSettings = LeaseSettings::build(
            $path,
            $requireExisting,
            $durability,
            $offload,
            $lockTimeout,
            $acquireTimeout,
            $maintenance,
        );
        $entry = $this->join($name);
        try {
            if ($entry->users > 1) {
                throw LitebackupError::invalidArgument($entry->name . ' is already leased by a call that has not returned');
            }
            if ($entry->opened && !$entry->leased) {
                throw LitebackupError::invalidArgument($entry->name . " is a backup rule's database in this pool; use use for it");
            }
            try {
                $this->prepareLease($entry, $leaseSettings);
            } catch (Throwable $error) {
                // This call failed before its work, so the release that ends
                // a lease it kept must not offload.
                $entry->lastCallFailed = true;
                throw $error;
            }
            // Cleared only once work and its durability wait finished, so a
            // call that exit or a fatal error ended reads as unfinished.
            $entry->callUnfinished = true;
            $finished = false;
            try {
                /** @var object $handle */
                $handle = $entry->handle;
                $workResult = $work($handle);
                unset($handle);
                if ($leaseSettings->durability !== null) {
                    $this->waitDurable($entry->name, $leaseSettings->durability);
                }
                $finished = true;
            } finally {
                unset($handle);
                $entry->lastCallFailed = !$finished;
                $entry->callUnfinished = false;
            }
            return $workResult;
        } finally {
            $this->leave($entry);
            $this->reportReleaseFailures();
        }
    }

    /**
     * Ends the lease of a database: closes the handle, then releases the
     * write lock once the final version reaches the lease's durability.
     * Throws LeaseCleanupError when closing or releasing fails, and
     * invalid_argument for a database the pool does not lease or that a
     * call is using.
     *
     * @param string $name
     */
    public function release(mixed $name): void
    {
        $this->ensureOwner();
        $name = Wire::text($name, 'name');
        $entry = $this->entries[$name] ?? null;
        if ($entry === null || !$entry->leased) {
            throw LitebackupError::invalidArgument($name . ' is not leased in this pool');
        }
        if ($entry->users > 0) {
            throw LitebackupError::invalidArgument($name . ' is used by a call that has not returned; release it after the call');
        }
        $this->releaseLease($entry);
    }

    /**
     * Refuses new calls, then releases every leased database and closes
     * every open one. A failure throws alone, several as an ErrorGroup; a
     * LeaseCleanupError names a database that stays acquired. A second
     * close returns at once. Closing from inside a use or lease call is
     * refused with invalid_argument.
     */
    public function close(): void
    {
        $this->ensureOwner();
        if ($this->activeCalls > 0) {
            throw LitebackupError::invalidArgument('a pool cannot close inside one of its calls; close it after the call returns');
        }
        if ($this->closing) {
            return;
        }
        $this->closing = true;
        $failures = [];
        foreach ($this->entries as $entry) {
            if ($entry->leased) {
                try {
                    $this->releaseLease($entry);
                } catch (LeaseCleanupError $error) {
                    $failures[] = $error;
                }
            } elseif ($entry->opened) {
                $failure = $this->closeRuleDatabase($entry);
                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
        }
        $this->releaseFailures = [];
        ErrorGroup::throwTogether($failures, 'closing the pool');
    }

    /** A pool dropped without close() closes as the shutdown function would. */
    public function __destruct()
    {
        $this->closeOnShutdown();
    }

    /**
     * Closes the pool when the PHP process ends without close(): best
     * effort, once, in the process that made it, and without output. A
     * lease whose call never finished, because exit or a fatal error ended
     * it, is released without offload.
     *
     * @internal
     */
    public function closeOnShutdown(): void
    {
        if ($this->closing || $this->ownerPID !== getmypid()) {
            return;
        }
        foreach ($this->entries as $entry) {
            if ($entry->callUnfinished) {
                $entry->lastCallFailed = true;
            }
            // A call that never returned still counts as using its entry.
            $entry->users = 0;
        }
        $this->activeCalls = 0;
        try {
            $this->close();
        } catch (Throwable) {
            // The shutdown has nowhere to report to; a database the pool could
            // not release stays acquired until a release or a session shutdown.
        }
    }

    private function ensureOwner(): void
    {
        if ($this->ownerPID === getmypid()) {
            return;
        }
        throw LitebackupError::invalidArgument(
            'this pool was made in process ' . $this->ownerPID . ' and cannot be used after a fork; make a new pool in this process',
        );
    }

    /** Counts a call on the name's entry, creating it, after the idle pass. */
    private function join(mixed $name): PoolEntry
    {
        $this->ensureOwner();
        if (!is_string($name) || $name === '') {
            throw LitebackupError::invalidArgument('a pool call needs a database name');
        }
        if ($this->closing) {
            throw new LitebackupError(ErrorCode::CLOSED, 'the pool is closed');
        }
        $this->closeIdleDatabases();
        $entry = $this->entries[$name] ??= new PoolEntry($name);
        $entry->users++;
        $this->activeCalls++;
        return $entry;
    }

    /** Ends a call on an entry; an entry with nothing open or leased is dropped. */
    private function leave(PoolEntry $entry): void
    {
        $entry->users--;
        $this->activeCalls--;
        $entry->lastUsed = Clock::now();
        $this->removeUnusedEntry($entry);
    }

    private function removeUnusedEntry(PoolEntry $entry): void
    {
        if ($entry->users === 0 && !$entry->opened && !$entry->leased && ($this->entries[$entry->name] ?? null) === $entry) {
            unset($this->entries[$entry->name]);
        }
    }

    /**
     * Closes the handle through the adapter, which gets the only reference
     * the pool holds; when the close fails, the entry keeps the handle if
     * anything else still holds it, so a later close tries again.
     */
    private function closeHandle(PoolEntry $entry): void
    {
        /** @var object $current */
        $current = $entry->handle;
        $weak = WeakReference::create($current);
        unset($current);
        try {
            $this->adapter->close($entry->detachHandle());
        } catch (Throwable $error) {
            $entry->handle = $weak->get();
            $entry->opened = $entry->handle !== null;
            throw $error;
        }
        $entry->opened = false;
    }

    /** Closes an open rule database and drops its entry; returns the close's error, or null. */
    private function closeRuleDatabase(PoolEntry $entry): ?Throwable
    {
        try {
            $this->closeHandle($entry);
        } catch (Throwable $error) {
            return $error;
        }
        $this->removeUnusedEntry($entry);
        return null;
    }

    /** Checks a kept lease, or acquires a new one, and opens its handle. */
    private function prepareLease(PoolEntry $entry, LeaseSettings $leaseSettings): void
    {
        if ($entry->leased) {
            $this->checkLease($entry);
        }
        $entry->leaseSettings = $leaseSettings;
        if (!$entry->leased) {
            $this->acquireLease($entry, $leaseSettings);
        } elseif (!$entry->opened) {
            $this->reopenLease($entry);
        }
    }

    private function ruleLookupNeeded(DatabaseStatus $status): bool
    {
        return $this->settings->idleClose === null || $status->journalMode === Wire::JOURNAL_MODE_ROLLBACK;
    }

    private function idleCloseSeconds(?BackupRule $rule): ?float
    {
        if ($this->settings->idleClose !== null) {
            return $this->settings->idleClose;
        }
        if ($rule !== null && $rule->maximumLocalDatabases > 0) {
            return self::$offloadIdleCloseSeconds;
        }
        return null;
    }

    /** @return array{string, bool} the release's durability and whether it offloads */
    private function releaseDurabilityAndOffload(PoolEntry $entry): array
    {
        $leaseSettings = $entry->leaseSettings;
        if ($leaseSettings === null) {
            return [Durability::SERVER, false];
        }
        return [$leaseSettings->releaseDurability(), $leaseSettings->offload && !$entry->lastCallFailed];
    }

    private function ruleDatabase(DatabaseStatus $status): bool
    {
        return $status->role === DatabaseStatus::ROLE_ORIGIN && !$status->acquired && !$status->failover
            && Wire::supplied($status->backupRuleId);
    }

    /** Closes a database its rule offloads while nothing has it open, once no use call is active. */
    private function closeAfterLastUse(PoolEntry $entry): void
    {
        if (!$entry->closeAfterUse || $entry->users !== 0 || !$entry->opened || $entry->leased) {
            return;
        }
        try {
            $this->closeHandle($entry);
        } catch (Throwable $error) {
            $this->releaseFailures[] = $this->useCloseFailed($entry, $error);
            return;
        }
        $this->removeUnusedEntry($entry);
    }

    /**
     * Opens a rule's database, bringing an offloaded copy back first, and
     * tries once more when the copy was offloaded between the two.
     */
    private function openRuleDatabase(PoolEntry $entry): void
    {
        for ($attempt = 0; ; $attempt++) {
            $status = $this->client->databases->get($entry->name);
            if (!$this->ruleDatabase($status)) {
                throw LitebackupError::invalidArgument(
                    $entry->name . " is not a backup rule's database on this machine; "
                    . 'use lease for a database that moves between machines',
                );
            }
            if ($status->state === DatabaseStatus::STATE_OFFLOADED) {
                $this->client->databases->acquire($entry->name);
            }
            try {
                $handle = $this->adapter->open($status->path);
            } catch (Throwable $error) {
                clearstatcache(true, $status->path);
                if ($attempt === 0 && !file_exists($status->path)) {
                    continue;
                }
                throw $error;
            }
            break;
        }
        $entry->handle = $handle;
        $entry->path = $status->path;
        $entry->opened = true;
        $rule = $this->ruleLookupNeeded($status) ? $this->databaseRule((string) $status->backupRuleId) : null;
        $entry->idleClose = $this->idleCloseSeconds($rule);
        $entry->closeAfterUse = $status->journalMode === Wire::JOURNAL_MODE_ROLLBACK && $rule !== null
            && $rule->offloadRollbackJournal === true;
    }

    /** The backup rule with ruleID, from the pool's cache or rules->list(). */
    private function databaseRule(string $ruleID): ?BackupRule
    {
        if (!isset($this->rules[$ruleID])) {
            foreach ($this->client->rules->list() as $listedRule) {
                $this->rules[$listedRule->id] = $listedRule;
            }
        }
        return $this->rules[$ruleID] ?? null;
    }

    /** Drops a lease whose lock this machine lost and throws lock_lost. */
    private function checkLease(PoolEntry $entry): void
    {
        try {
            $status = $this->client->databases->get($entry->name);
        } catch (LitebackupError $error) {
            if ($error->errorCode !== ErrorCode::NOT_FOUND) {
                throw $error;
            }
            $status = null;
        }
        if ($status !== null && $status->acquired && $status->state !== DatabaseStatus::STATE_CONFLICTED) {
            return;
        }
        $this->dropLostLease($entry);
        throw new LitebackupError(
            ErrorCode::LOCK_LOST,
            'this machine no longer has the write lock of ' . $entry->name . ', so the pool dropped its lease; '
            . 'the next lease acquires it again',
        );
    }

    /** Opens the handle of a lease whose release failed after its handle closed. */
    private function reopenLease(PoolEntry $entry): void
    {
        try {
            $entry->handle = $this->adapter->open($entry->path);
        } catch (Throwable $error) {
            $entry->lastCallFailed = true;
            throw $error;
        }
        $entry->opened = true;
    }

    /** Acquires the write lock and opens the database; one that does not open is released again at once. */
    private function acquireLease(PoolEntry $entry, LeaseSettings $leaseSettings): void
    {
        if ($leaseSettings->path !== null && !$leaseSettings->requireExisting) {
            self::createEmptyFile($leaseSettings->path);
        }
        $acquired = $this->client->databases->acquire(
            $entry->name,
            path: $leaseSettings->path,
            maintenance: $leaseSettings->maintenance,
            machineLabel: $this->settings->machineLabel,
            timeout: $leaseSettings->acquireTimeout,
            lockTimeout: $leaseSettings->lockTimeout,
        );
        if (!$acquired->database->acquired) {
            throw LitebackupError::invalidArgument($entry->name . " is a backup rule's database on this machine; use use for it");
        }
        $path = $acquired->database->path !== ''
            ? $acquired->database->path
            : ($leaseSettings->path !== null ? Wire::absolutePath($leaseSettings->path) : '');
        $entry->path = $path;
        try {
            $handle = $this->adapter->open($path);
        } catch (Throwable $openError) {
            // Nothing is open: release the lock again, without offload.
            $entry->leased = true;
            $entry->lastCallFailed = true;
            try {
                $this->releaseLease($entry);
            } catch (LeaseCleanupError $releaseError) {
                throw new ErrorGroup('leasing ' . $entry->name, [$openError, $releaseError]);
            }
            throw $openError;
        }
        $entry->handle = $handle;
        $entry->opened = true;
        $entry->leased = true;
    }

    /** Syncs now and returns once the server, or the bucket with cloud, has the version the sync reached. */
    private function waitDurable(string $name, string $durability): void
    {
        $status = $this->client->databases->sync($name);
        if ($durability !== Durability::CLOUD) {
            return;
        }
        $target = $status->syncedServer->version;
        while ($status->syncedCloud->version < $target) {
            Clock::sleep(self::$cloudPollSeconds);
            $status = $this->client->databases->get($name);
        }
    }

    /**
     * Closes the handle and releases the lock within one cleanup timeout.
     * Closing is never cut short; the release gets what closing left.
     */
    private function releaseLease(PoolEntry $entry): void
    {
        $deadline = Clock::now() + $this->settings->cleanupSeconds;
        if ($entry->opened) {
            try {
                $this->closeHandle($entry);
            } catch (Throwable $error) {
                throw $this->closeFailed($entry, $error);
            }
        }
        [$durability, $offload] = $this->releaseDurabilityAndOffload($entry);
        $timeout = max($deadline - Clock::now(), self::MINIMUM_RELEASE_SECONDS);
        try {
            $this->client->databases->release($entry->name, durability: $durability, offload: $offload, timeout: $timeout);
        } catch (Throwable $error) {
            throw $this->releaseFailed($entry, $durability, $error);
        }
        $entry->leased = false;
        $this->removeUnusedEntry($entry);
    }

    /** Closes the handle of a database whose lock was lost, quietly, and drops the lease. */
    private function dropLostLease(PoolEntry $entry): void
    {
        if ($entry->opened) {
            try {
                $this->closeHandle($entry);
            } catch (Throwable $error) {
                Warnings::report('closing ' . $entry->name . ' after its write lock was lost failed: ' . $error->getMessage());
                $entry->handle = null;
                $entry->opened = false;
            }
        }
        $entry->leased = false;
    }

    private function closeFailed(PoolEntry $entry, Throwable $error): LeaseCleanupError
    {
        return new LeaseCleanupError(
            LeaseCleanupError::STEP_CLOSE,
            $entry->name,
            'closing ' . $entry->name . ' failed, so it was not released and stays acquired by this machine: '
            . $error->getMessage() . '. Close every connection to ' . $entry->path . ', then call $pool->release('
            . json_encode($entry->name, JSON_UNESCAPED_SLASHES) . ') again',
            ['step' => LeaseCleanupError::STEP_CLOSE, 'name' => $entry->name, 'path' => $entry->path, 'acquired' => true],
            $error,
        );
    }

    private function releaseFailed(PoolEntry $entry, string $durability, Throwable $error): LeaseCleanupError
    {
        return new LeaseCleanupError(
            LeaseCleanupError::STEP_RELEASE,
            $entry->name,
            'releasing ' . $entry->name . ' failed: ' . $error->getMessage() . '. Its file at ' . $entry->path
            . ' is intact and this machine might still have its write lock; call $pool->release('
            . json_encode($entry->name, JSON_UNESCAPED_SLASHES) . ') again',
            ['step' => LeaseCleanupError::STEP_RELEASE, 'name' => $entry->name, 'path' => $entry->path, 'durability' => $durability],
            $error,
        );
    }

    private function useCloseFailed(PoolEntry $entry, Throwable $error): LeaseCleanupError
    {
        return new LeaseCleanupError(
            LeaseCleanupError::STEP_CLOSE,
            $entry->name,
            'closing ' . $entry->name . ' after its last use failed, so it stays open and its rule does not offload it: '
            . $error->getMessage() . '. The pool tries again at its next call',
            ['step' => LeaseCleanupError::STEP_CLOSE, 'name' => $entry->name, 'path' => $entry->path, 'acquired' => false],
            $error,
        );
    }

    /**
     * Closes rule databases past their idle close and releases leases past
     * idleRelease, among the entries no call uses. It runs at the start of
     * each call, as PHP has no thread to run it in the background.
     */
    private function closeIdleDatabases(): void
    {
        $now = Clock::now();
        foreach ($this->entries as $entry) {
            if ($entry->users > 0 || $entry->lastUsed === null) {
                continue;
            }
            $idle = $now - $entry->lastUsed;
            $idleRelease = $this->settings->idleRelease;
            if ($entry->leased && $idleRelease !== null && $idle >= $idleRelease) {
                try {
                    $this->releaseLease($entry);
                } catch (LeaseCleanupError $error) {
                    $this->releaseFailures[] = $error;
                }
                continue;
            }
            $closeNow = !$entry->leased && $entry->opened
                && ($entry->closeAfterUse || ($entry->idleClose !== null && $idle >= $entry->idleClose));
            if (!$closeNow) {
                continue;
            }
            try {
                $this->closeHandle($entry);
            } catch (Throwable $error) {
                // The entry keeps its handle, so the next call tries again.
                Warnings::report('closing the idle database ' . $entry->name . ' failed: ' . $error->getMessage());
                continue;
            }
            $this->removeUnusedEntry($entry);
        }
    }

    /**
     * Passes the release failures the idle pass found to onReleaseError,
     * once the call that found them is done, so the callback may call
     * release and close; reports them as warnings without a callback.
     */
    private function reportReleaseFailures(): void
    {
        if ($this->activeCalls > 0) {
            return;
        }
        while ($this->releaseFailures !== []) {
            $failure = array_shift($this->releaseFailures);
            $callback = $this->settings->onReleaseError;
            if ($callback === null) {
                Warnings::report('the pool could not release ' . $failure->name . ': ' . $failure->getMessage());
                continue;
            }
            $callback($failure);
        }
    }

    /**
     * Creates an empty file and its directory unless something is already
     * there. The daemon treats an empty file as no copy: a new database when
     * the server has none, replaced by the server's head otherwise.
     */
    public static function createEmptyFile(string $path): void
    {
        $file = Wire::absolutePath($path);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw LitebackupError::invalidArgument('creating ' . $file . ' failed: ' . (error_get_last()['message'] ?? 'mkdir failed'));
        }
        $created = @fopen($file, 'x');
        if ($created !== false) {
            fclose($created);
            return;
        }
        clearstatcache(true, $file);
        if (!file_exists($file) && !is_link($file)) {
            throw LitebackupError::invalidArgument('creating ' . $file . ' failed: ' . (error_get_last()['message'] ?? 'fopen failed'));
        }
    }
}
