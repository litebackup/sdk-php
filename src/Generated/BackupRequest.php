<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class BackupRequest
{
    public const WATCH_INOTIFY = 'inotify';
    public const WATCH_POLL = 'poll';

    public function __construct(
        /** absolute file, directory, or glob with optional ** segments */
        public string $path,
        /** the database name for a single path; must already be a valid name */
        public ?string $name,
        /**
         * name prefix such as app/ (a trailing / is added when missing); names are the prefix plus
         * the relative file path
         */
        public ?string $prefix,
        public ?string $watch,
        /**
         * when positive, offload the least recently changed idle databases past this count once
         * offload_durability holds them
         */
        public ?int $maximumLocalDatabases,
        /**
         * what maximum_local_databases waits for before it removes a copy; server, the default,
         * once the server holds its last version, cloud once the bucket holds it too
         */
        public ?string $offloadDurability,
        /**
         * lets maximum_local_databases offload rollback-journal databases too, on the operator's
         * word that nothing keeps them open while idle; WAL databases are offloaded either way
         */
        public ?bool $offloadRollbackJournal,
    ) {}

    /** Decodes BackupRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            path: WireModel::string($fields, 'path', true),
            name: WireModel::string($fields, 'name', false),
            prefix: WireModel::string($fields, 'prefix', false),
            watch: WireModel::string($fields, 'watch', false),
            maximumLocalDatabases: WireModel::integer($fields, 'maximum_local_databases', false),
            offloadDurability: WireModel::string($fields, 'offload_durability', false),
            offloadRollbackJournal: WireModel::boolean($fields, 'offload_rollback_journal', false),
        );
    }
}
