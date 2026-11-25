<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class PrefixReplica
{
    public function __construct(
        public string $id,
        public string $prefix,
        /** the directory that receives <directory>/<name relative to the prefix> */
        public string $directory,
        public string $writeProtection,
        /** how many databases under the prefix this machine replicates */
        public int $databaseCount,
    ) {}

    /** Decodes PrefixReplica from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            id: WireModel::string($fields, 'id', true),
            prefix: WireModel::string($fields, 'prefix', true),
            directory: WireModel::string($fields, 'directory', true),
            writeProtection: WireModel::string($fields, 'write_protection', true),
            databaseCount: WireModel::integer($fields, 'database_count', true),
        );
    }
}
