<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\ErrorCodes;

/**
 * Codes the SDK raises itself, with status 0, and the daemon codes worth
 * matching against LitebackupError::$errorCode. Codes the daemon answers with
 * that are not listed here are kept verbatim.
 */
final class ErrorCode
{
    /** An argument failed local validation; nothing was sent. */
    public const INVALID_ARGUMENT = ErrorCodes::INVALID_ARGUMENT;
    /** No litebackup binary was found to start the daemon or a foreground backup. */
    public const BINARY_NOT_FOUND = 'binary_not_found';
    /** litebackup launch ran and failed. */
    public const LAUNCH_FAILED = ErrorCodes::LAUNCH_FAILED;
    /**
     * litebackup launch refused apiKey, a credential other than the one the
     * session's client locked its databases with; session->shutdown with the
     * current credential releases them first.
     */
    public const DRAIN_REQUIRED = ErrorCodes::DRAIN_REQUIRED;
    /** The socket refused the connection or closed it mid-call. */
    public const CONNECTION_FAILED = 'connection_failed';
    /** The daemon is older than Version::MINIMUM_DAEMON_VERSION. */
    public const DAEMON_UNSUPPORTED = 'daemon_unsupported';
    /** The wait was cancelled; the daemon may still finish the operation. */
    public const CANCELLED = ErrorCodes::CANCELLED;
    /** The client was closed. */
    public const CLOSED = 'closed';
    /** The daemon answered 200 with a body the SDK cannot decode. */
    public const INVALID_RESPONSE = 'invalid_response';
    /** A pool could not close or release a leased database; the error is a LeaseCleanupError. */
    public const LEASE_CLEANUP_FAILED = 'lease_cleanup_failed';
    /** The file a restore or an acquire would write, or a journal file beside it, already exists. */
    public const FILE_EXISTS = ErrorCodes::FILE_EXISTS;
    /**
     * A backup rule on this machine owns the database and its write lock, so
     * it cannot be released; stop its backup instead.
     */
    public const RULE_DATABASE = ErrorCodes::RULE_DATABASE;
    /** The path already has a backup rule with different settings; details['rule'] is the saved rule. */
    public const RULE_EXISTS = ErrorCodes::RULE_EXISTS;
    /** Another machine writes the database. */
    public const LOCK_UNAVAILABLE = ErrorCodes::LOCK_UNAVAILABLE;
    /** This machine lost the database's write lock, as a pool's lease does when another machine broke it. */
    public const LOCK_LOST = ErrorCodes::LOCK_LOST;
    public const NOT_FOUND = ErrorCodes::NOT_FOUND;
    /** A process still has the database open, so it cannot be released. */
    public const STILL_OPEN = ErrorCodes::STILL_OPEN;
    /** The operation's timeout ran out. */
    public const TIMEOUT = ErrorCodes::TIMEOUT;
    /** This SDK is older than the daemon accepts. */
    public const SDK_UNSUPPORTED = ErrorCodes::SDK_UNSUPPORTED;
    /** Another job or a continuous backup has a foreground backup's writer lock. */
    public const BUSY = ErrorCodes::BUSY;
    /**
     * An events cursor is older than the oldest event the daemon keeps; a
     * watch ends with it, and the caller picks a new cursor.
     */
    public const CURSOR_EXPIRED = ErrorCodes::CURSOR_EXPIRED;
}
