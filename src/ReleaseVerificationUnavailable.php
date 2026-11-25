<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/** Release signatures cannot be checked here: PHP's sodium extension is not loaded. */
final class ReleaseVerificationUnavailable extends RuntimeException {}
