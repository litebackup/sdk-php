<?php

declare(strict_types=1);

namespace Litebackup;

use Throwable;

/**
 * A failed release of a leased database, with code lease_cleanup_failed;
 * getPrevious() is why.
 *
 * step is close when closing the handle failed: the database was not
 * released and stays acquired by this machine with its file as it is; close
 * every connection, then call $pool->release($name) again. It is release
 * when the release itself failed, as with still_open or an unreachable
 * server: the file is intact; call $pool->release($name) again before
 * another machine needs it.
 */
final class LeaseCleanupError extends LitebackupError
{
    /** Closing the handle failed, so the database was not released and stays acquired by this machine. */
    public const STEP_CLOSE = 'close';
    /** The release itself failed; the file is intact. */
    public const STEP_RELEASE = 'release';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $step,
        public readonly string $name,
        string $message,
        array $details,
        Throwable $previous,
    ) {
        parent::__construct(ErrorCode::LEASE_CLEANUP_FAILED, $message, 0, $details, $previous);
    }
}
