<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class StopBackupResult
{
    public function __construct(
        public bool $leasePending,
    ) {}

    /** Decodes StopBackupResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            leasePending: WireModel::boolean($fields, 'lease_pending', true),
        );
    }
}
