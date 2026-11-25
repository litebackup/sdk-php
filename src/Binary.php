<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * Finding the litebackup binary. The package bundles none, as Composer has
 * no platform packages, and nothing is downloaded at runtime.
 */
final class Binary
{
    public const PATH_ENVIRONMENT = 'LITEBACKUP_PATH';

    /** The one line that installs the litebackup command on Linux and macOS for whoever runs it. */
    public static function installCommand(): string
    {
        return 'curl -fsSL https://litebackup.com/cli.sh | bash';
    }

    public static function executable(string $path): bool
    {
        clearstatcache(true, $path);
        return is_file($path) && is_executable($path);
    }

    /**
     * Finds the litebackup binary: the explicit path, then LITEBACKUP_PATH,
     * then litebackup on PATH, then ~/.local/bin/litebackup. Throws
     * binary_not_found with the install command when there is none, or when
     * the explicit path or LITEBACKUP_PATH names no file.
     *
     * @param array<string, string> $environment
     */
    public static function litebackupBinary(array $environment, ?string $binaryPath = null): string
    {
        $override = trim((string) $binaryPath);
        if ($override === '') {
            $override = trim($environment[self::PATH_ENVIRONMENT] ?? '');
        }
        if ($override !== '') {
            // An explicit path that names no file never falls back to another
            // binary; a file that cannot run fails later, as launch_failed.
            if (self::missingPath($override)) {
                throw new LitebackupError(
                    ErrorCode::BINARY_NOT_FOUND,
                    'the litebackup binary ' . $override . ' does not exist; set binaryPath or '
                    . self::PATH_ENVIRONMENT . ' to it, or install it with: ' . self::installCommand(),
                );
            }
            return $override;
        }
        $found = self::pathBinary($environment['PATH'] ?? '');
        if ($found !== null) {
            return $found;
        }
        $home = Wire::supplied($environment['HOME'] ?? null) ? $environment['HOME'] : Paths::homeDirectory();
        $local = $home . '/.local/bin/litebackup';
        if (self::executable($local)) {
            return $local;
        }
        throw new LitebackupError(
            ErrorCode::BINARY_NOT_FOUND,
            'the litebackup command is not installed; set ' . self::PATH_ENVIRONMENT
            . ' or install it with: ' . self::installCommand(),
        );
    }

    /** The first executable litebackup in a PATH value. */
    public static function pathBinary(string $searchPath): ?string
    {
        foreach (explode(PATH_SEPARATOR, $searchPath) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = $directory . '/litebackup';
            if (self::executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Whether nothing is at path: it, or a directory on its way, does not
     * exist. A path behind a directory this user may not enter is not missing.
     */
    public static function missingPath(string $path): bool
    {
        clearstatcache(true, $path);
        if (@stat($path) !== false) {
            return false;
        }
        // stat fails for a missing file and for one behind a directory this
        // user may not enter; only the first has a missing step on its way.
        $directory = $path;
        while (($parent = dirname($directory)) !== $directory) {
            clearstatcache(true, $parent);
            if (@stat($parent) !== false) {
                // A file where a directory should be is missing too.
                return !is_dir($parent) || is_executable($parent);
            }
            $directory = $parent;
        }
        return true;
    }

    /**
     * Why starting binary failed when it is there: it cannot run, because
     * the interpreter its #! line names, or its dynamic loader, is not on
     * this machine, or because it is not executable. The message names that
     * line rather than repeating the file's path as if it were missing.
     */
    public static function startFailureMessage(string $binary, string $reason): string
    {
        if (self::missingPath($binary)) {
            return 'starting ' . $binary . ' failed: ' . $reason;
        }
        $interpreterLine = self::scriptInterpreterLine($binary);
        $unusableBinary = 'starting ' . $binary . ' failed: it cannot run because';
        if ($interpreterLine !== '') {
            return $unusableBinary . ' the interpreter its first line names is not on this machine: ' . $interpreterLine;
        }
        return $unusableBinary . ' its interpreter or dynamic loader is not on this machine';
    }

    /** The #! line that starts the file at path, or '' when it starts with none or cannot be read. */
    public static function scriptInterpreterLine(string $path): string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return '';
        }
        $start = (string) fread($file, 256);
        fclose($file);
        $firstLine = Wire::validText(explode("\n", $start, 2)[0]);
        return str_starts_with($firstLine, '#!') ? trim($firstLine) : '';
    }
}
