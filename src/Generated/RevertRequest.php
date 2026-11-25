<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class RevertRequest
{
    public function __construct(
        public string $name,
        /** the version whose content the new head holds; set version or time */
        public ?int $version,
        /** revert to the newest version at or before this time; set version or time */
        public ?DateTimeImmutable $time,
    ) {}

    /** Decodes RevertRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            version: WireModel::integer($fields, 'version', false),
            time: WireModel::time($fields, 'time'),
        );
    }
}
