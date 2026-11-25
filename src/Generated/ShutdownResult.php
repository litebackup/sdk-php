<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class ShutdownResult
{
    /**
     * @param list<DrainFailure> $failures
     */
    public function __construct(
        /** how many databases the drain synced as asked and released */
        public int $releasedCount,
        /**
         * every database the drain could not sync, wait for, or release, each keeping any write
         * lock it holds, and every conflicted copy whose writes are not on the server, with
         * conflict; empty only when the drain released every database it covers, which is the one
         * case the daemon stops
         */
        public array $failures,
    ) {}

    /** Decodes ShutdownResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            releasedCount: WireModel::integer($fields, 'released_count', true),
            failures: WireModel::models($fields, 'failures', DrainFailure::fromWire(...), true),
        );
    }
}
