<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/**
 * The daemon closed the connection, or broke HTTP, before a whole answer.
 *
 * @internal
 */
final class TransportConnectionBroken extends RuntimeException {}
