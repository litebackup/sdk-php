<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class BackupList
{
    /**
     * @param list<BackupRule> $rules
     */
    public function __construct(
        public array $rules,
    ) {}

    /** Decodes BackupList from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            rules: WireModel::models($fields, 'rules', BackupRule::fromWire(...), true),
        );
    }
}
