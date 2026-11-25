<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class ReplicaList
{
    /**
     * @param list<PrefixReplica> $prefixes
     * @param list<DatabaseStatus> $replicas
     */
    public function __construct(
        public array $prefixes,
        public array $replicas,
    ) {}

    /** Decodes ReplicaList from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            prefixes: WireModel::models($fields, 'prefixes', PrefixReplica::fromWire(...), true),
            replicas: WireModel::models($fields, 'replicas', DatabaseStatus::fromWire(...), true),
        );
    }
}
