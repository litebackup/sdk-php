<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use Litebackup\WireModel;

final readonly class AcquireRequest
{
    public function __construct(
        public string $name,
        /** absolute path of the local file; defaults to the path this machine uses */
        public ?string $path,
        /** checkpoint and, past the freelist threshold, VACUUM on release */
        public ?bool $maintenance,
        /**
         * replace a local file that diverged from the server head with the head, discarding its
         * writes the head lacks; without it such a file is refused with 409 conflict and left
         * untouched
         */
        public ?bool $discardLocal,
        /** lets Litebackup delete the released copy once offload_durability holds it */
        public ?bool $offloadOnRelease,
        /**
         * what an offload of the released copy waits for; server, the default, once the server
         * holds its last version, cloud once the bucket holds it too. A release with offload sets
         * it to the release's durability.
         */
        public ?string $offloadDurability,
        /** the name this machine shows on the server and as the writer of this database only */
        public ?string $machineLabel,
        /** bound on downloading the head */
        public ?int $timeoutMillis,
        /**
         * how long to wait for the write lock while another machine holds it, trying again until it
         * is released; 0 refuses at once with lock_unavailable. It adds to the timeout.
         */
        public ?int $lockTimeoutMillis,
        /**
         * the caller's file mode creation mask, such as 18 for 022; a file the acquire creates, the
         * head downloaded where none was or the empty file an offload removed, gets 0666 less it,
         * as one the caller created would. A copy the head replaces keeps its bits. Absent uses the
         * client's own umask. The litebackup command sends its own.
         */
        public ?int $umask,
    ) {}

    /** Decodes AcquireRequest from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            path: WireModel::string($fields, 'path', false),
            maintenance: WireModel::boolean($fields, 'maintenance', false),
            discardLocal: WireModel::boolean($fields, 'discard_local', false),
            offloadOnRelease: WireModel::boolean($fields, 'offload_on_release', false),
            offloadDurability: WireModel::string($fields, 'offload_durability', false),
            machineLabel: WireModel::string($fields, 'machine_label', false),
            timeoutMillis: WireModel::integer($fields, 'timeout_millis', false),
            lockTimeoutMillis: WireModel::integer($fields, 'lock_timeout_millis', false),
            umask: WireModel::integer($fields, 'umask', false),
        );
    }
}
