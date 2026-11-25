<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class RestoreResult
{
    public function __construct(
        public string $name,
        public string $path,
        public int $version,
        public int $pageCount,
        public int $pageSize,
        public string $stateDigest,
    ) {}

    /** Decodes RestoreResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            path: WireModel::string($fields, 'path', true),
            version: WireModel::integer($fields, 'version', true),
            pageCount: WireModel::integer($fields, 'page_count', true),
            pageSize: WireModel::integer($fields, 'page_size', true),
            stateDigest: WireModel::string($fields, 'state_digest', true),
        );
    }
}
