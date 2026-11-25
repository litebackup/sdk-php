<?php

declare(strict_types=1);

namespace Litebackup;

/** The SDK's version and the oldest daemon it talks to. */
final class Version
{
    /** Sent with every call. Daemons refuse SDKs older than their minimum with sdk_unsupported. */
    public const VERSION = '1.6.0';

    /** Connecting to an older daemon fails with daemon_unsupported. */
    public const MINIMUM_DAEMON_VERSION = '1.6.0';
}
