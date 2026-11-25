<?php

declare(strict_types=1);

namespace Litebackup;

/** The monotonic clock every deadline in the SDK uses, in seconds. */
final class Clock
{
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /** Waits seconds; a negative or zero wait returns at once. */
    public static function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
