<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

/** Either name for one replica, or prefix and directory for a prefix. */
final readonly class StopReplicaRequest
{
    public function __construct(
        public ?string $name,
        public ?string $prefix,
        /** with prefix, the directory the prefix replica was started with */
        public ?string $directory,
    ) {}

    /** Decodes StopReplicaRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', false),
            prefix: WireModel::string($fields, 'prefix', false),
            directory: WireModel::string($fields, 'directory', false),
        );
    }
}
