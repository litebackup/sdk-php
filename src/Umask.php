<?php

declare(strict_types=1);

namespace Litebackup;

/** The caller's file mode creation mask, which acquire and restore send. */
final class Umask
{
    /**
     * This process's umask, so a file the daemon creates gets the bits a file
     * this process created would. On Linux it reads the Umask line of
     * /proc/self/status, which leaves the mask alone; elsewhere, as on macOS,
     * it sets the mask and puts it back at once. PHP has no threads in one
     * request, so nothing else in the process creates a file in that moment.
     */
    public static function currentUmask(): int
    {
        $mask = self::statusUmask('/proc/self/status');
        if ($mask !== null) {
            return $mask;
        }
        $previous = umask(0);
        umask($previous);
        return $previous;
    }

    /** Reads the octal Umask line of a /proc status file, null when it has none. */
    public static function statusUmask(string $path): ?int
    {
        $status = @file_get_contents($path);
        if ($status === false) {
            return null;
        }
        if (preg_match('/^Umask:[ \t]*([0-7]+)[ \t]*$/m', $status, $match) !== 1) {
            return null;
        }
        $mask = (int) octdec($match[1]);
        return $mask >= 0 && $mask <= 0o777 ? $mask : null;
    }

    /** The umask a request sends: the one passed in, checked, or this process's. */
    public static function requestUmask(mixed $umask): int
    {
        if ($umask === null) {
            return self::currentUmask();
        }
        if (!is_int($umask) || $umask < 0 || $umask > 0o777) {
            throw LitebackupError::invalidArgument('umask must be between 0 and 0777');
        }
        return $umask;
    }
}
