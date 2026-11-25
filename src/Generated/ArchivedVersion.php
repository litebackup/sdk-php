<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

/** The newest version the server reported the primary bucket holds. */
final readonly class ArchivedVersion
{
    public function __construct(
        public int $version,
        /** when the server recorded the version as archived */
        public ?DateTimeImmutable $archived,
    ) {}

    /** Decodes ArchivedVersion from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            version: WireModel::integer($fields, 'version', true),
            archived: WireModel::time($fields, 'archived'),
        );
    }
}
