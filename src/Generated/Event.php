<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class Event
{
    public function __construct(
        public int $id,
        public ?DateTimeImmutable $time,
        public ?string $name,
        public string $type,
        public string $message,
        /** for role_changed, the new role */
        public ?string $role,
        /** for refused, the refused file; for path_unreadable, the path the rule could not read */
        public ?string $path,
    ) {}

    /** Decodes Event from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            id: WireModel::integer($fields, 'id', true),
            time: WireModel::time($fields, 'time'),
            name: WireModel::string($fields, 'name', false),
            type: WireModel::string($fields, 'type', true),
            message: WireModel::string($fields, 'message', true),
            role: WireModel::string($fields, 'role', false),
            path: WireModel::string($fields, 'path', false),
        );
    }
}
