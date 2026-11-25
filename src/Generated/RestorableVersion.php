<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class RestorableVersion
{
    public function __construct(
        public int $version,
        public string $stateDigest,
        /** when the version was synced */
        public ?DateTimeImmutable $time,
        /**
         * when the server recorded the primary bucket's object of this version as archived; absent
         * when the primary bucket does not hold the version, as for one synced between archive
         * passes, which restores only until history pruning removes its segment
         */
        public ?DateTimeImmutable $archived,
    ) {}

    /** Decodes RestorableVersion from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            version: WireModel::integer($fields, 'version', true),
            stateDigest: WireModel::string($fields, 'state_digest', true),
            time: WireModel::time($fields, 'time'),
            archived: WireModel::time($fields, 'archived'),
        );
    }
}
