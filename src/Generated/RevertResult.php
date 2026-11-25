<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class RevertResult
{
    public function __construct(
        public DatabaseStatus $database,
        /**
         * the version whose content the new head holds; it and every version after it stay
         * restorable
         */
        public int $sourceVersion,
        /** the new head, one past the head before the revert */
        public int $version,
        /** the new head's state digest, which is the source version's */
        public string $stateDigest,
    ) {}

    /** Decodes RevertResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            database: WireModel::model($fields, 'database', DatabaseStatus::fromWire(...), true),
            sourceVersion: WireModel::integer($fields, 'source_version', true),
            version: WireModel::integer($fields, 'version', true),
            stateDigest: WireModel::string($fields, 'state_digest', true),
        );
    }
}
