<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\Durability;

/**
 * One lease call's settings; the release that ends the lease uses the last call's.
 *
 * @internal
 */
final readonly class LeaseSettings
{
    private function __construct(
        public ?string $path,
        public bool $requireExisting,
        public ?string $durability,
        public bool $offload,
        public ?float $lockTimeout,
        public ?float $acquireTimeout,
        public bool $maintenance,
    ) {}

    public static function build(
        mixed $path,
        mixed $requireExisting,
        mixed $durability,
        mixed $offload,
        mixed $lockTimeout,
        mixed $acquireTimeout,
        mixed $maintenance,
    ): self {
        if ($durability !== null && !(is_string($durability) && in_array($durability, Durability::ALL, true))) {
            throw LitebackupError::invalidArgument("a lease's durability must be server or cloud");
        }
        /** @var string|null $durability */
        return new self(
            Wire::supplied($path) ? Wire::pathText($path) : null,
            Wire::flag($requireExisting, 'requireExisting'),
            $durability,
            Wire::flag($offload, 'offload'),
            Wire::seconds($lockTimeout, 'the lock timeout'),
            Wire::seconds($acquireTimeout, 'the acquire timeout'),
            Wire::flag($maintenance, 'maintenance'),
        );
    }

    public function releaseDurability(): string
    {
        return $this->durability ?? Durability::SERVER;
    }
}
