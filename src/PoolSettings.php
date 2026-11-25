<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;

/**
 * What a pool was created with, checked once. A null idleRelease never
 * releases an unused lease.
 *
 * @internal
 */
final readonly class PoolSettings
{
    /** Seconds that limit closing and releasing a leased database together unless cleanupTimeout says otherwise. */
    public const DEFAULT_CLEANUP_TIMEOUT = 600.0;
    public const DEFAULT_IDLE_RELEASE = 60.0;

    /**
     * @param (Closure(LeaseCleanupError): void)|null $onReleaseError
     */
    private function __construct(
        public ?float $idleClose,
        public ?float $idleRelease,
        public float $cleanupSeconds,
        public ?string $machineLabel,
        public ?Closure $onReleaseError,
    ) {}

    public static function build(
        mixed $idleClose,
        mixed $idleRelease,
        mixed $cleanupTimeout,
        mixed $machineLabel,
        mixed $onReleaseError,
    ): self {
        $cleanupSeconds = $cleanupTimeout === null
            ? self::DEFAULT_CLEANUP_TIMEOUT
            : Wire::seconds($cleanupTimeout, 'the cleanup timeout');
        if ($cleanupSeconds === null) {
            throw LitebackupError::invalidArgument('the cleanup timeout must be positive');
        }
        if ($onReleaseError !== null && !$onReleaseError instanceof Closure) {
            throw LitebackupError::invalidArgument('onReleaseError must be a Closure, got ' . get_debug_type($onReleaseError));
        }
        /** @var (Closure(LeaseCleanupError): void)|null $onReleaseError */
        return new self(
            Wire::seconds($idleClose, 'the idle close'),
            $idleRelease === null ? self::DEFAULT_IDLE_RELEASE : Wire::seconds($idleRelease, 'the idle release'),
            $cleanupSeconds,
            Wire::optionalText($machineLabel, 'machineLabel'),
            $onReleaseError,
        );
    }
}
