<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class ReleaseRequest
{
    public function __construct(
        public string $name,
        /** server returns once the final sync is acknowledged; cloud waits for the bucket too */
        public ?string $durability,
        /**
         * remove the local file once durability holds the released version, the server with server
         * and the bucket with cloud; an offload refused as not_durable is left to a later offload
         * pass
         */
        public ?bool $offload,
        /** how long to wait for the database to close and for the chosen durability */
        public ?int $timeoutMillis,
    ) {}

    /** Decodes ReleaseRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            durability: WireModel::string($fields, 'durability', false),
            offload: WireModel::boolean($fields, 'offload', false),
            timeoutMillis: WireModel::integer($fields, 'timeout_millis', false),
        );
    }
}
