<?php

declare(strict_types=1);

namespace Litebackup;

use Litebackup\Generated\Durability;

/**
 * backups->once: one foreground litebackup backup job, as the CLI runs it.
 *
 * @internal
 */
final class Foreground
{
    /** How many of the job's file diagnostics a failed backup's message lists. */
    public const MAXIMUM_FILE_DIAGNOSTICS = 20;
    /** How long a job has to exit after SIGTERM before it gets SIGKILL. */
    public const STOP_GRACE_SECONDS = 15.0;
    /**
     * How long a job's error output may stay open after the job exited, in a
     * process it started, before reading stops.
     */
    public const DRAIN_SECONDS = 1.0;

    /** How long a stopped job has before SIGKILL; tests shorten it. */
    public static float $stopGraceSeconds = self::STOP_GRACE_SECONDS;
    /** How long past its timeout a job runs before it is killed; tests shorten it. */
    public static float $deadlineGraceSeconds = Wire::DEADLINE_GRACE_SECONDS;

    /**
     * The litebackup command line of one foreground job.
     *
     * @return non-empty-list<string>
     */
    public static function backupCommand(
        string $binary,
        mixed $source,
        ?string $session,
        mixed $name,
        mixed $prefix,
        mixed $durability,
        mixed $timeout,
    ): array {
        if (!Wire::supplied($source)) {
            throw LitebackupError::invalidArgument('backup needs a path');
        }
        $name = Wire::optionalText($name, 'name');
        $prefix = Wire::optionalText($prefix, 'prefix');
        if ($name !== null && $prefix !== null) {
            throw LitebackupError::invalidArgument('choose a name or a prefix');
        }
        if ($name !== null && ($name === '' || str_ends_with($name, '/'))) {
            throw LitebackupError::invalidArgument('expected an exact name');
        }
        $durability = Wire::checkDurability($durability) ?? Durability::SERVER;
        $command = [$binary, 'backup', '--stdout-format', 'json', '--durability', $durability];
        if (Wire::supplied($session)) {
            array_push($command, '--session', (string) $session);
        }
        $timeoutSeconds = Wire::seconds($timeout, 'the backup timeout');
        if ($timeoutSeconds !== null) {
            array_push($command, '--timeout', Wire::millis($timeoutSeconds) . 'ms');
        }
        array_push($command, '--', Wire::absolutePath($source));
        if ($name !== null) {
            $command[] = $name;
        }
        if (Wire::supplied($prefix)) {
            $command[] = str_ends_with((string) $prefix, '/') ? (string) $prefix : $prefix . '/';
        }
        return $command;
    }

    /**
     * The job's structured error on this line of its error output, or null for a log line.
     *
     * @return array{code: string, message: string}|null
     */
    public static function reportedFailure(string $line): ?array
    {
        $parsed = json_decode($line, true);
        if (!is_array($parsed) || !is_string($parsed['code'] ?? null) || !is_string($parsed['message'] ?? null)) {
            return null;
        }
        return ['code' => $parsed['code'], 'message' => $parsed['message']];
    }

    /**
     * The job's final structured report, followed by each file diagnostic it
     * printed before it, or its text. Each file diagnostic names the file,
     * and the machine that writes it for a busy one.
     */
    public static function backupFailure(string $errorOutput, string $exitDescription): LitebackupError
    {
        $finalReport = null;
        $diagnostics = [];
        foreach (preg_split('/\r?\n/', trim($errorOutput)) ?: [] as $line) {
            $failure = self::reportedFailure($line);
            if ($failure === null) {
                continue;
            }
            $finalReport = $failure;
            // A file reported twice is listed once, so the limit counts distinct files.
            if (count($diagnostics) < self::MAXIMUM_FILE_DIAGNOSTICS && !in_array($failure['message'], $diagnostics, true)) {
                $diagnostics[] = $failure['message'];
            }
        }
        if ($finalReport === null) {
            $text = trim($errorOutput);
            return new LitebackupError(
                'backup_failed',
                $text === '' ? 'foreground backup failed with ' . $exitDescription : $text,
            );
        }
        $message = $finalReport['message'];
        $listed = [$message];
        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic, $listed, true)) {
                continue;
            }
            $listed[] = $diagnostic;
            $message .= "\n" . $diagnostic;
        }
        return new LitebackupError($finalReport['code'], $message);
    }

    /**
     * binary_not_found when the binary is missing, launch_failed when it
     * cannot run: a file that is there but fails to start has a missing
     * script interpreter or loader.
     */
    public static function startFailure(string $binary, string $reason): LitebackupError
    {
        $code = Binary::missingPath($binary) ? ErrorCode::BINARY_NOT_FOUND : ErrorCode::LAUNCH_FAILED;
        return new LitebackupError($code, Binary::startFailureMessage($binary, $reason));
    }

    /**
     * Starts a job, with its standard output discarded and its error output read.
     *
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     */
    public static function startJob(array $command, array $environment): ChildCommand
    {
        if (Binary::missingPath($command[0])) {
            throw self::startFailure($command[0], 'no such file');
        }
        return ChildCommand::start($command, $environment, false);
    }

    /**
     * How long to wait for a job: its timeout plus the grace that lets the
     * job report its own timeout first.
     */
    public static function jobDeadline(mixed $timeout): ?float
    {
        $timeoutSeconds = Wire::seconds($timeout, 'the backup timeout');
        return $timeoutSeconds === null ? null : $timeoutSeconds + self::$deadlineGraceSeconds;
    }

    /** Stops a running job: SIGTERM, then SIGKILL when it has not exited within the grace. */
    public static function stopJob(ChildCommand $job): void
    {
        $job->signal(ChildCommand::SIGNAL_TERMINATE);
        if (!$job->awaitExit(self::$stopGraceSeconds)) {
            $job->signal(ChildCommand::SIGNAL_KILL);
            $job->awaitExit(null);
        }
    }

    public static function timedOutBackup(): LitebackupError
    {
        return new LitebackupError(ErrorCode::TIMEOUT, 'the foreground backup did not finish within its timeout');
    }
}
