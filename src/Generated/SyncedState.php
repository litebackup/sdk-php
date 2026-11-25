<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

/** A version the server acknowledged. Clients decide what durability is enough. */
final readonly class SyncedState
{
    public function __construct(
        public int $version,
        public ?DateTimeImmutable $time,
    ) {}

    /** Decodes SyncedState from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            version: WireModel::integer($fields, 'version', true),
            time: WireModel::time($fields, 'time'),
        );
    }
}
