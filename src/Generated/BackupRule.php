<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class BackupRule
{
    public function __construct(
        public string $id,
        /** the path or glob */
        public string $path,
        /** the resolved remote name for a single file, or empty for a directory or glob */
        public string $name,
        public string $prefix,
        public string $watch,
        public int $maximumLocalDatabases,
        /** what maximum_local_databases waits for before it removes a copy */
        public string $offloadDurability,
        public bool $offloadRollbackJournal,
        public bool $failover,
    ) {}

    /** Decodes BackupRule from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            id: WireModel::string($fields, 'id', true),
            path: WireModel::string($fields, 'path', true),
            name: WireModel::string($fields, 'name', true),
            prefix: WireModel::string($fields, 'prefix', true),
            watch: WireModel::string($fields, 'watch', true),
            maximumLocalDatabases: WireModel::integer($fields, 'maximum_local_databases', true),
            offloadDurability: WireModel::string($fields, 'offload_durability', true),
            offloadRollbackJournal: WireModel::boolean($fields, 'offload_rollback_journal', true),
            failover: WireModel::boolean($fields, 'failover', true),
        );
    }
}
