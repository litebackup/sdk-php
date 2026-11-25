<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class FailoverRequest
{
    public function __construct(
        /** absolute path of the database, the same on every machine */
        public string $path,
        /** the database name; defaults to the relative file path */
        public ?string $name,
        /** bound on the first download of a replica */
        public ?int $timeoutMillis,
        /**
         * replace a file at the path that differs from the server head with the head, discarding
         * its writes the head lacks; without it such a file is refused with 409 conflict and left
         * untouched
         */
        public ?bool $discardLocal,
    ) {}

    /** Decodes FailoverRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            path: WireModel::string($fields, 'path', true),
            name: WireModel::string($fields, 'name', false),
            timeoutMillis: WireModel::integer($fields, 'timeout_millis', false),
            discardLocal: WireModel::boolean($fields, 'discard_local', false),
        );
    }
}
