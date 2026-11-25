<?php

declare(strict_types=1);

namespace Litebackup;

/** One published file of a release. */
final readonly class ReleaseFile
{
    public function __construct(
        public string $name,
        public string $filename,
        public string $url,
        public string $sha256,
        public int $size,
        public string $os,
        public string $architecture,
    ) {}
}
