<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class Status
{
    /**
     * @param ?list<Event> $unreadablePaths
     * @param list<DatabaseStatus> $databases
     */
    public function __construct(
        /** Pass this name as after to read the next page. Omitted on the last page. */
        public ?string $next,
        public string $session,
        public string $server,
        public string $clientVersion,
        public string $sessionId,
        public ?DateTimeImmutable $leaseExpires,
        public string $watch,
        /**
         * The soft limit on open files the daemon runs with. Each active backup holds about four,
         * and the daemon lowers its default number of active backups to fit.
         */
        public ?int $openFileLimit,
        /**
         * the paths a backup rule cannot read, most recent first; the events API has the history
         */
        public ?array $unreadablePaths,
        public array $databases,
    ) {}

    /** Decodes Status from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            next: WireModel::string($fields, 'next', false),
            session: WireModel::string($fields, 'session', true),
            server: WireModel::string($fields, 'server', true),
            clientVersion: WireModel::string($fields, 'client_version', true),
            sessionId: WireModel::string($fields, 'session_id', true),
            leaseExpires: WireModel::time($fields, 'lease_expires'),
            watch: WireModel::string($fields, 'watch', true),
            openFileLimit: WireModel::integer($fields, 'open_file_limit', false),
            unreadablePaths: WireModel::models($fields, 'unreadable_paths', Event::fromWire(...), false),
            databases: WireModel::models($fields, 'databases', DatabaseStatus::fromWire(...), true),
        );
    }
}
