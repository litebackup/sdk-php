<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class Failover
{
    public const ROLE_ORIGIN = 'origin';
    public const ROLE_REPLICA = 'replica';

    public function __construct(
        public string $name,
        public string $path,
        public string $role,
        public DatabaseStatus $database,
        public Writer $writer,
    ) {}

    /** Decodes Failover from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            path: WireModel::string($fields, 'path', true),
            role: WireModel::string($fields, 'role', true),
            database: WireModel::model($fields, 'database', DatabaseStatus::fromWire(...), true),
            writer: WireModel::model($fields, 'writer', Writer::fromWire(...), true),
        );
    }
}
