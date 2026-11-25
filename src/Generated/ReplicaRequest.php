<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

/** Either name and path, for one database, or prefix and directory, for every database under it. */
final readonly class ReplicaRequest
{
    public const WRITE_PROTECTION_LOCK = 'lock';
    public const WRITE_PROTECTION_DETECT = 'detect';
    public const WRITE_PROTECTION_NONE = 'none';

    public function __construct(
        public ?string $name,
        /** with name, the replica file, defaulting to the path this machine uses */
        public ?string $path,
        /** name prefix such as app; replicates every database under it */
        public ?string $prefix,
        /** with prefix, the directory that receives <directory>/<name relative to the prefix> */
        public ?string $directory,
        /**
         * lock (default) holds the SQLite write lock so app writes get SQLITE_BUSY while readers
         * carry on; detect rebases after a local write; none trusts apps to open with mode=ro
         */
        public ?string $writeProtection,
        /** bound on downloading the first version of a single replica */
        public ?int $timeoutMillis,
    ) {}

    /** Decodes ReplicaRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', false),
            path: WireModel::string($fields, 'path', false),
            prefix: WireModel::string($fields, 'prefix', false),
            directory: WireModel::string($fields, 'directory', false),
            writeProtection: WireModel::string($fields, 'write_protection', false),
            timeoutMillis: WireModel::integer($fields, 'timeout_millis', false),
        );
    }
}
