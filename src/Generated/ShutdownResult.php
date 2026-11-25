<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class ShutdownResult
{
    /**
     * @param list<DrainFailure> $failed
     */
    public function __construct(
        /** how many databases the drain synced as asked and released */
        public int $released,
        /**
         * every database the drain could not sync, wait for, or release, each keeping any write
         * lock it holds, and every conflicted copy whose writes are not on the server, with
         * conflict; empty only when the drain released every database it covers, which is the one
         * case the daemon stops
         */
        public array $failed,
    ) {}

    /** Decodes ShutdownResult from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            released: WireModel::integer($fields, 'released', true),
            failed: WireModel::models($fields, 'failed', DrainFailure::fromWire(...), true),
        );
    }
}
