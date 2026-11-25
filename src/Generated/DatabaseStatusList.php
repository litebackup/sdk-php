<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class DatabaseStatusList
{
    /**
     * @param list<DatabaseStatus> $databases
     */
    public function __construct(
        /** Pass this name as after to read the next page. Omitted on the last page. */
        public ?string $next,
        public array $databases,
    ) {}

    /** Decodes DatabaseStatusList from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            next: WireModel::string($fields, 'next', false),
            databases: WireModel::models($fields, 'databases', DatabaseStatus::fromWire(...), true),
        );
    }
}
