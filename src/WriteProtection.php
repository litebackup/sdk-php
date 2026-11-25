<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\ReplicaRequest;

/** How a replica keeps the app from writing its file. */
final class WriteProtection
{
    public const LOCK = ReplicaRequest::WRITE_PROTECTION_LOCK;
    public const DETECT = ReplicaRequest::WRITE_PROTECTION_DETECT;
    public const NONE = ReplicaRequest::WRITE_PROTECTION_NONE;
    public const ALL = [self::LOCK, self::DETECT, self::NONE];
}
