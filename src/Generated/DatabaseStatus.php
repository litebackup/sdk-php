<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class DatabaseStatus
{
    public const ROLE_ORIGIN = 'origin';
    public const ROLE_REPLICA = 'replica';
    public const STATE_PENDING = 'pending';
    public const STATE_SYNCING = 'syncing';
    public const STATE_RETRYING = 'retrying';
    public const STATE_SYNCED = 'synced';
    public const STATE_WAITING = 'waiting';
    public const STATE_OFFLOADED = 'offloaded';
    public const STATE_STOPPED = 'stopped';
    public const STATE_CONFLICTED = 'conflicted';
    public const STATE_ERROR = 'error';

    public function __construct(
        public string $name,
        public string $path,
        /**
         * origin when this machine writes the database and backs it up; replica for a live readonly
         * copy
         */
        public string $role,
        /**
         * pending while a backup or failover origin holds no write lock, as after a start or a
         * drain, until a sync takes it (a start takes back the lock of each backup a drain left
         * pending while the head is still the version it last synced); syncing while changes
         * stream; retrying while an origin's syncs fail, with last_error saying why and
         * next_retry_time when the next attempt runs; synced when the server holds every change: a
         * backup or failover origin whose last sync landed with no change seen after it, until its
         * next change, or a released database whose last version is on the server; waiting while
         * another machine is the origin; offloaded when the local file was removed because the
         * server, or the bucket when the offload asked for it, holds its last version; stopped for
         * a stopped replica; conflicted when this copy was refused and left as it is, because its
         * file differs from the server head or its write lock was lost or broken; error with
         * last_error
         */
        public string $state,
        /** the database is acquired and released by a worker rather than backed up continuously */
        public bool $acquired,
        /** the database belongs to a failover and changes role when the origin does */
        public bool $failover,
        /**
         * the backup or failover rule that tracks the database on this machine; absent for an
         * acquired database and a replica
         */
        public ?string $backupRuleId,
        public bool $localChanged,
        public SyncedState $syncedServer,
        public ArchivedVersion $syncedCloud,
        /**
         * wal, rollback, or unknown before the first scan. A full scan of a rollback-journal
         * database holds a read lock that blocks the app's writers until it ends, so it runs at
         * full speed rather than at --scan-bytes-per-second, and the first scan past 256 MB records
         * a rollback_scan event with its duration. Switch such a database to WAL mode (PRAGMA
         * journal_mode = WAL), where scans never block writers; Litebackup never changes the
         * journal mode itself.
         */
        public string $journalMode,
        public int $pageCount,
        public int $syncCount,
        public int $hintMisses,
        public ?string $lastFullScanReason,
        /**
         * the machine label of the last writer this machine saw; empty once this machine released
         * the database, drained it, or offloaded a copy whose write lock it held, as no writer is
         * known then
         */
        public ?string $writerMachineLabel,
        public ?string $lastError,
        public ?DateTimeImmutable $lastErrorTime,
        /**
         * when the first change the server lacks was seen; absent when the server has every change
         * seen. It survives restarts and may be earlier than the real first change, never later.
         */
        public ?DateTimeImmutable $firstUnsyncedChangeTime,
        /** when the next attempt of a failed sync runs; absent when none is scheduled */
        public ?DateTimeImmutable $nextRetryTime,
        /**
         * set on a failover origin while this machine drains, and on one a drain released, which
         * holds no write lock until it takes one again; a failover then reports its role as
         * unknown, as after a draining event
         */
        public ?bool $draining,
        /** the name this machine shows on the server and as the writer of its databases */
        public ?string $machineLabel,
        public ?bool $maintenance,
        /** the client deletes the released copy once offload_durability holds it */
        public ?bool $offloadOnRelease,
        /**
         * what an offload of this acquired copy waits for: server removes the released copy once
         * the server holds its last version, cloud once the bucket holds it too. An acquire, a
         * release with offload, and an explicit offload each save the durability they asked for.
         * Absent when the database is not acquired
         */
        public ?string $offloadDurability,
    ) {}

    /** Decodes DatabaseStatus from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            path: WireModel::string($fields, 'path', true),
            role: WireModel::string($fields, 'role', true),
            state: WireModel::string($fields, 'state', true),
            acquired: WireModel::boolean($fields, 'acquired', true),
            failover: WireModel::boolean($fields, 'failover', true),
            backupRuleId: WireModel::string($fields, 'backup_rule_id', false),
            localChanged: WireModel::boolean($fields, 'local_changed', true),
            syncedServer: WireModel::model($fields, 'synced_server', SyncedState::fromWire(...), true),
            syncedCloud: WireModel::model($fields, 'synced_cloud', ArchivedVersion::fromWire(...), true),
            journalMode: WireModel::string($fields, 'journal_mode', true),
            pageCount: WireModel::integer($fields, 'page_count', true),
            syncCount: WireModel::integer($fields, 'sync_count', true),
            hintMisses: WireModel::integer($fields, 'hint_misses', true),
            lastFullScanReason: WireModel::string($fields, 'last_full_scan_reason', false),
            writerMachineLabel: WireModel::string($fields, 'writer_machine_label', false),
            lastError: WireModel::string($fields, 'last_error', false),
            lastErrorTime: WireModel::time($fields, 'last_error_time'),
            firstUnsyncedChangeTime: WireModel::time($fields, 'first_unsynced_change_time'),
            nextRetryTime: WireModel::time($fields, 'next_retry_time'),
            draining: WireModel::boolean($fields, 'draining', false),
            machineLabel: WireModel::string($fields, 'machine_label', false),
            maintenance: WireModel::boolean($fields, 'maintenance', false),
            offloadOnRelease: WireModel::boolean($fields, 'offload_on_release', false),
            offloadDurability: WireModel::string($fields, 'offload_durability', false),
        );
    }
}
