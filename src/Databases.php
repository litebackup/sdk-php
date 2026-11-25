<?php

declare(strict_types=1);

namespace Litebackup;

use Generator;
use Litebackup\Generated\AcquireResult;
use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\DatabaseStatusList;
use Litebackup\Generated\Durability;
use Litebackup\Generated\ReleaseResult;
use Litebackup\Generated\RestorableVersion;
use Litebackup\Generated\RestoreResult;
use Litebackup\Generated\RevertResult;
use Litebackup\Generated\VersionList;
use Litebackup\Generated\Writer;

/** Databases on this machine and on the server. */
final class Databases extends Group
{
    /** @param string $name */
    public function get(mixed $name): DatabaseStatus
    {
        return $this->client->call(Wire::database($name));
    }

    /**
     * One page; pass its next as after to continue.
     *
     * @param string $after
     * @param int $limit
     */
    public function page(mixed $after = '', mixed $limit = 100): DatabaseStatusList
    {
        return $this->client->call(Wire::databasesPage($after, $limit));
    }

    /**
     * Every database on this machine, a page at a time.
     *
     * @param int $limit
     * @return Generator<int, DatabaseStatus, mixed, void>
     */
    public function all(mixed $limit = 100): Generator
    {
        Wire::pageLimit($limit);
        return $this->allFrom($limit);
    }

    /** @return list<DatabaseStatus> */
    public function list(): array
    {
        return iterator_to_array($this->all(), false);
    }

    /**
     * @param string $name
     * @param int $after
     * @param int|null $limit
     */
    public function versionsPage(mixed $name, mixed $after = 0, mixed $limit = null): VersionList
    {
        return $this->client->call(Wire::versionsPage($name, $after, $limit));
    }

    /**
     * The versions the server restores, oldest first; at most limit when set.
     *
     * @param string $name
     * @param int $after
     * @param int|null $limit
     * @return Generator<int, RestorableVersion, mixed, void>
     */
    public function allVersions(mixed $name, mixed $after = 0, mixed $limit = null): Generator
    {
        $name = Wire::text($name, 'name');
        $after = Wire::optionalInteger($after, 'after') ?? 0;
        $limit = Wire::optionalInteger($limit, 'limit');
        if ($limit !== null && $limit < 0) {
            throw LitebackupError::invalidArgument('the limit must not be negative');
        }
        return $this->versionsFrom($name, $after, $limit);
    }

    /**
     * @param string $name
     * @param int $after
     * @param int|null $limit
     * @return list<RestorableVersion>
     */
    public function versions(mixed $name, mixed $after = 0, mixed $limit = null): array
    {
        return iterator_to_array($this->allVersions($name, $after, $limit), false);
    }

    /**
     * Writes a version to a new file and checks it. An existing file is
     * never overwritten (file_exists). The file gets 0666 less umask, this
     * process's umask when null.
     *
     * @param string $name
     * @param string|\Stringable|\SplFileInfo $path
     * @param int|null $version
     * @param \DateTimeInterface|string|null $time
     * @param int|null $umask
     */
    public function restore(
        mixed $name,
        mixed $path = null,
        mixed $version = null,
        mixed $time = null,
        mixed $umask = null,
    ): RestoreResult {
        return $this->client->call(Wire::restore($name, $path, $version, $time, $umask));
    }

    /**
     * Rolls a database back under its own name: a past version's content
     * becomes a new head, one past the current head. Pass version or time,
     * one of them; every later version stays restorable. The database must
     * be one this machine acquired or backs up, and its file closed
     * (still_open otherwise); a replica or a failover standby is refused
     * (invalid_argument), and a file holding writes the server lacks is
     * refused with conflict and left untouched.
     *
     * @param string $name
     * @param int|null $version
     * @param \DateTimeInterface|string|null $time
     */
    public function revert(mixed $name, mixed $version = null, mixed $time = null): RevertResult
    {
        return $this->client->call(Wire::revert($name, $version, $time));
    }

    /** @param string $name */
    public function sync(mixed $name): DatabaseStatus
    {
        return $this->client->call(Wire::sync($name));
    }

    /**
     * Removes the local file of a released database once the server has its
     * last version, or the bucket with cloud.
     *
     * @param string $name
     * @param string|null $durability
     */
    public function offload(mixed $name, mixed $durability = null): DatabaseStatus
    {
        return $this->client->call(Wire::offload($name, $durability));
    }

    /** @param string $name */
    public function writer(mixed $name): Writer
    {
        return $this->client->call(Wire::writer($name));
    }

    /**
     * Locks the database for this machine and makes the local file the
     * server's head. A pool's lease also opens, keeps, and releases it. A
     * local file that diverged from the head is refused with conflict and
     * left untouched; discardLocal replaces it with the head instead. While
     * another machine has the write lock, the daemon asks again for up to
     * lockTimeout; without it the acquire fails at once with lock_unavailable.
     *
     * @param string $name
     * @param string|\Stringable|\SplFileInfo|null $path
     * @param bool $maintenance
     * @param bool $offloadOnRelease
     * @param string $offloadDurability
     * @param string|null $machineLabel
     * @param int|float|null $timeout seconds
     * @param int|float|null $lockTimeout seconds
     * @param int|null $umask
     * @param bool $discardLocal
     */
    public function acquire(
        mixed $name,
        mixed $path = null,
        mixed $maintenance = false,
        mixed $offloadOnRelease = false,
        mixed $offloadDurability = Durability::SERVER,
        mixed $machineLabel = null,
        mixed $timeout = null,
        mixed $lockTimeout = null,
        mixed $umask = null,
        mixed $discardLocal = false,
    ): AcquireResult {
        return $this->client->call(Wire::acquire(
            $name,
            $path,
            $maintenance,
            $offloadOnRelease,
            $offloadDurability,
            $machineLabel,
            $timeout,
            $lockTimeout,
            $umask,
            $discardLocal,
        ));
    }

    /**
     * Syncs a final time and releases the write lock. Close every
     * connection first, or it fails with still_open.
     *
     * @param string $name
     * @param string|null $durability
     * @param bool $offload
     * @param int|float|null $timeout seconds
     */
    public function release(
        mixed $name,
        mixed $durability = null,
        mixed $offload = false,
        mixed $timeout = null,
    ): ReleaseResult {
        return $this->client->call(Wire::release($name, $durability, $offload, $timeout));
    }

    /**
     * A pool that keeps databases open for calls in this PHP process,
     * opened and closed with adapter. See Pool for idleClose, idleRelease,
     * cleanupTimeout, machineLabel, and onReleaseError.
     *
     * @param int|float|null $idleClose seconds a rule's database stays open unused
     * @param int|float|null $idleRelease seconds a lease stays acquired unused; 60 by default, 0 for ever
     * @param int|float|null $cleanupTimeout seconds closing and releasing a lease may take; 600 by default
     * @param string|null $machineLabel
     * @param (\Closure(LeaseCleanupError): void)|null $onReleaseError
     */
    public function pool(
        Adapter $adapter,
        mixed $idleClose = null,
        mixed $idleRelease = null,
        mixed $cleanupTimeout = null,
        mixed $machineLabel = null,
        mixed $onReleaseError = null,
    ): Pool {
        $settings = PoolSettings::build($idleClose, $idleRelease, $cleanupTimeout, $machineLabel, $onReleaseError);
        return new Pool($this->client, $adapter, $settings);
    }

    /** @return Generator<int, DatabaseStatus, mixed, void> */
    private function allFrom(int $limit): Generator
    {
        $after = '';
        while (true) {
            $listing = $this->page($after, $limit);
            yield from $listing->databases;
            if ($listing->next === null || $listing->next === '') {
                return;
            }
            $after = $listing->next;
        }
    }

    /** @return Generator<int, RestorableVersion, mixed, void> */
    private function versionsFrom(string $name, int $after, ?int $limit): Generator
    {
        if ($limit === 0) {
            return;
        }
        $listed = 0;
        while (true) {
            $listing = $this->versionsPage($name, $after, $limit === null ? null : $limit - $listed);
            foreach ($listing->versions as $version) {
                yield $version;
                $listed++;
                if ($limit !== null && $listed >= $limit) {
                    return;
                }
            }
            $next = $listing->next ?? 0;
            if ($next === 0 || $next <= $after || $listing->versions === []) {
                return;
            }
            $after = $next;
        }
    }
}
