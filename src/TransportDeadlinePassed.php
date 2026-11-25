<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/**
 * The call's deadline passed before the daemon answered.
 *
 * @internal
 */
final class TransportDeadlinePassed extends RuntimeException {}
