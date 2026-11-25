<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class VersionList
{
    /**
     * @param list<RestorableVersion> $versions
     */
    public function __construct(
        public array $versions,
        /** pass as after to read the next page */
        public ?int $next,
    ) {}

    /** Decodes VersionList from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            versions: WireModel::models($fields, 'versions', RestorableVersion::fromWire(...), true),
            next: WireModel::integer($fields, 'next', false),
        );
    }
}
