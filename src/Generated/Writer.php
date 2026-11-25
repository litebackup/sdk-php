<?php

// Code generated from client-openapi.yaml by scripts/generate. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

use DateTimeImmutable;
use Litebackup\WireModel;

final readonly class Writer
{
    public function __construct(
        public string $name,
        /** whether some machine holds the write lock and its lease still runs */
        public bool $active,
        /** whether this machine's session holds the write lock */
        public bool $thisMachine,
        /** the machine label of the writer, or empty when nobody holds the lock */
        public string $writerMachineLabel,
        /** when the writer took the lock */
        public ?DateTimeImmutable $lockAcquired,
        /** on the server's clock */
        public ?DateTimeImmutable $leaseExpires,
        /** how long the lease still runs, negative once it ran out */
        public ?int $leaseRemainingMillis,
        /** the writer's lease ran out, so another machine may take over */
        public bool $lapsed,
    ) {}

    /** Decodes Writer from a JSON object. */
    public static function fromWire(mixed $value): self
    {
        $fields = WireModel::fields($value, self::class);
        return new self(
            name: WireModel::string($fields, 'name', true),
            active: WireModel::boolean($fields, 'active', true),
            thisMachine: WireModel::boolean($fields, 'this_machine', true),
            writerMachineLabel: WireModel::string($fields, 'writer_machine_label', true),
            lockAcquired: WireModel::time($fields, 'lock_acquired'),
            leaseExpires: WireModel::time($fields, 'lease_expires'),
            leaseRemainingMillis: WireModel::integer($fields, 'lease_remaining_millis', false),
            lapsed: WireModel::boolean($fields, 'lapsed', true),
        );
    }
}
