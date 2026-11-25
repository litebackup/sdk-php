<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/**
 * A failover read that follow()'s own time limit cut short; follow()
 * returns and the role stays as it was.
 *
 * @internal
 */
final class FollowTimeSpent extends RuntimeException {}
