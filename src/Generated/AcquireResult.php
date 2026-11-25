<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class AcquireResult
{
    public const DECISION_CREATED = 'created';
    public const DECISION_MATCHED = 'matched';
    public const DECISION_RESUMED = 'resumed';
    public const DECISION_DOWNLOADED = 'downloaded';
    public const DECISION_FAST_FORWARDED = 'fast_forwarded';
    public const DECISION_REPLACED = 'replaced';

    public function __construct(
        public DatabaseStatus $database,
        /** what the acquire did to the local file */
        public string $decision,
    ) {}

    /** Decodes AcquireResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            database: WireModel::model($fields, 'database', DatabaseStatus::fromWire(...), true),
            decision: WireModel::string($fields, 'decision', true),
        );
    }
}
