<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;
use Throwable;

/**
 * Every failure the SDK raises: the daemon's answer, or an error the SDK
 * raised itself with status 0.
 *
 * The wire code is errorCode, a string such as lock_unavailable, because
 * Exception::getCode() is final and an integer. details holds the answer's
 * fields besides code and message under their wire names, such as rule for
 * rule_exists from backups->start. For lease_cleanup_failed it holds step,
 * name, path, and acquired or durability. The underlying error, when there
 * is one, is getPrevious().
 */
class LitebackupError extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The error for a failed local check. */
    public static function invalidArgument(string $message): self
    {
        return new self(ErrorCode::INVALID_ARGUMENT, $message);
    }

    /** The code and the message, as a log line shows them. */
    public function describe(): string
    {
        return $this->errorCode . ': ' . $this->getMessage();
    }
}
