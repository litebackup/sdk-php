<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

/** The replica for a name, or the prefix replica for a prefix. */
final readonly class ReplicaResult
{
    public function __construct(
        public ?DatabaseStatus $database,
        public ?PrefixReplica $prefixReplica,
    ) {}

    /** Decodes ReplicaResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            database: WireModel::model($fields, 'database', DatabaseStatus::fromWire(...), false),
            prefixReplica: WireModel::model($fields, 'prefix_replica', PrefixReplica::fromWire(...), false),
        );
    }
}
