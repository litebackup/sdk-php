<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/** A release whose signature does not match Litebackup's release keys. */
final class ReleaseSignatureError extends RuntimeException
{
    public function __construct(string $message = "the release signature does not match Litebackup's release keys")
    {
        parent::__construct($message);
    }
}
