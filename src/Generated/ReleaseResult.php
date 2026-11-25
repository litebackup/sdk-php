<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class ReleaseResult
{
    public function __construct(
        public DatabaseStatus $database,
        public bool $checkpointed,
        public bool $vacuumed,
        /** when the release removed the local file; absent when it kept the file */
        public ?DateTimeImmutable $offloaded,
    ) {}

    /** Decodes ReleaseResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            database: WireModel::model($fields, 'database', DatabaseStatus::fromWire(...), true),
            checkpointed: WireModel::boolean($fields, 'checkpointed', true),
            vacuumed: WireModel::boolean($fields, 'vacuumed', true),
            offloaded: WireModel::time($fields, 'offloaded'),
        );
    }
}
