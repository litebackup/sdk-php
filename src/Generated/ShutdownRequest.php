<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class ShutdownRequest
{
    public const DURABILITY_NONE = 'none';
    public const DURABILITY_SERVER = 'server';
    public const DURABILITY_CLOUD = 'cloud';

    public function __construct(
        public ?string $durability,
        public ?int $timeoutMillis,
    ) {}

    /** Decodes ShutdownRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            durability: WireModel::string($fields, 'durability', false),
            timeoutMillis: WireModel::integer($fields, 'timeout_millis', false),
        );
    }
}
