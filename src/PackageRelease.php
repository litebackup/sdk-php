<?php

declare(strict_types=1);

namespace Litebackup;

/** A package release read from its verified signed document. */
final readonly class PackageRelease
{
    /**
     * @param list<string> $channels
     * @param list<ReleaseFile> $files
     */
    public function __construct(
        public string $version,
        public array $channels,
        public array $files,
    ) {}

    /** The file with name; throws ReleaseNotListed when the release has none. */
    public function file(string $name): ReleaseFile
    {
        foreach ($this->files as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }
        throw new ReleaseNotListed('release ' . $this->version . ' has no file ' . $name);
    }
}
