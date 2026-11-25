<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class RestoreRequest
{
    public function __construct(
        public string $name,
        /** absolute path of a new file in an existing directory */
        public string $path,
        /** the version to restore; the head when neither version nor time is set */
        public ?int $version,
        /** restore the newest version at or before this time */
        public ?DateTimeImmutable $time,
        /**
         * the caller's file mode creation mask, such as 18 for 022; the file gets 0666 less it, as
         * one the caller created would. Absent uses the client's own umask. The litebackup command
         * sends its own.
         */
        public ?int $umask,
    ) {}

    /** Decodes RestoreRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            path: WireModel::string($fields, 'path', true),
            version: WireModel::integer($fields, 'version', false),
            time: WireModel::time($fields, 'time'),
            umask: WireModel::integer($fields, 'umask', false),
        );
    }
}
