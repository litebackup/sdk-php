<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

/**
 * What happened to a database: role_changed (role is origin or replica, and message is text),
 * draining (a failover origin stops being the origin before a drain releases its lock: its role is
 * unknown until the next role_changed), refused (path names the refused file, such as a failover's
 * file that holds writes the server lacks), path_unreadable (path names the path a backup rule
 * could not read), and the others by their names.
 */
final class EventType
{
    public const ACQUIRED = 'acquired';
    public const DOWNLOAD_FINISHED = 'download_finished';
    public const DOWNLOAD_STARTED = 'download_started';
    public const DRAINING = 'draining';
    public const IMAGE_WRITTEN = 'image_written';
    public const LOCAL_WRITE = 'local_write';
    public const LOCK_LOST = 'lock_lost';
    public const OFFLOADED = 'offloaded';
    public const PATH_UNREADABLE = 'path_unreadable';
    public const REBASED = 'rebased';
    public const REFUSED = 'refused';
    public const RELEASED = 'released';
    public const REPLICATING = 'replicating';
    public const RESTORED = 'restored';
    public const REVERTED = 'reverted';
    public const ROLE_CHANGED = 'role_changed';
    public const ROLLBACK_SCAN = 'rollback_scan';
    public const SERVER_GENERATION = 'server_generation';
    public const STOPPED = 'stopped';
    public const TRACKED = 'tracked';

    /** Every value this SDK knows; a newer daemon may send others. */
    public const ALL = [
        self::ACQUIRED,
        self::DOWNLOAD_FINISHED,
        self::DOWNLOAD_STARTED,
        self::DRAINING,
        self::IMAGE_WRITTEN,
        self::LOCAL_WRITE,
        self::LOCK_LOST,
        self::OFFLOADED,
        self::PATH_UNREADABLE,
        self::REBASED,
        self::REFUSED,
        self::RELEASED,
        self::REPLICATING,
        self::RESTORED,
        self::REVERTED,
        self::ROLE_CHANGED,
        self::ROLLBACK_SCAN,
        self::SERVER_GENERATION,
        self::STOPPED,
        self::TRACKED,
    ];
}
