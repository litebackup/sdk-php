<?php

declare(strict_types=1);

namespace Litebackup;

use RuntimeException;

/** A package or release the metadata does not list. */
final class ReleaseNotListed extends RuntimeException {}
