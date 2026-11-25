<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class OffloadRequest
{
    public function __construct(
        public string $name,
        /**
         * what the offload waits for before it removes the copy: server, the default, once the
         * server holds its synced version; cloud once the bucket holds it too. Until then it is
         * refused with not_durable.
         */
        public ?string $durability,
    ) {}

    /** Decodes OffloadRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            durability: WireModel::string($fields, 'durability', false),
        );
    }
}
