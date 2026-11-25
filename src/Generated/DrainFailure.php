<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class DrainFailure
{
    public function __construct(
        public string $name,
        public string $code,
        public string $message,
    ) {}

    /** Decodes DrainFailure from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            code: WireModel::string($fields, 'code', true),
            message: WireModel::string($fields, 'message', true),
        );
    }
}
