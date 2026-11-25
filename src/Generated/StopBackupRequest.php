<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class StopBackupRequest
{
    public function __construct(
        /** Exact remote name of a database with its own backup rule on this machine. */
        public ?string $name,
        /**
         * ID returned by start or listed by rules. Stops the whole rule, including future matches.
         */
        public ?string $ruleId,
    ) {}

    /** Decodes StopBackupRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', false),
            ruleId: WireModel::string($fields, 'rule_id', false),
        );
    }
}
