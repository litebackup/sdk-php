<?php

declare(strict_types=1);

namespace Litebackup\Integrations;

use RuntimeException;

/** A framework's litebackup setting that cannot be registered; the command or the worker stops with it. */
final class ConfigurationError extends RuntimeException {}
