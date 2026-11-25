<?php

declare(strict_types=1);

namespace Litebackup;

use Closure;

/**
 * The warnings the pool and the integrations report: to the logger when
 * one is set, such as a framework's, and to PHP's error_log otherwise.
 */
final class Warnings
{
    /** @var (Closure(string): void)|null */
    public static ?Closure $logger = null;

    public static function report(string $message): void
    {
        $line = 'litebackup: ' . $message;
        if (self::$logger !== null) {
            (self::$logger)($line);
            return;
        }
        error_log($line);
    }
}
