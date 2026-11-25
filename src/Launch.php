<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * Starting a session's daemon with litebackup launch, as the CLI does. The
 * launch command locks the session's launch lock, so concurrent processes
 * start one daemon. Launches are never retried.
 */
final class Launch
{
    public const LAUNCH_TIMEOUT_SECONDS = 30.0;
    /**
     * How long a finished command's output pipes may stay open, in a process
     * it started, before reading stops.
     */
    public const DRAIN_SECONDS = 1.0;

    /** Whether something accepts connections on the socket. */
    public static function socketListening(string $socketPath): bool
    {
        $socket = @stream_socket_client('unix://' . $socketPath, $errorNumber, $errorText, 1.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /**
     * The launch command line and its environment.
     *
     * @param array<string, string> $environment
     * @return array{non-empty-list<string>, array<string, string>}
     */
    public static function launchCommand(string $session, array $environment, ?string $apiKey, ?string $binaryPath): array
    {
        $binary = Binary::litebackupBinary($environment, $binaryPath);
        $launchEnvironment = $environment;
        $launchEnvironment[Binary::PATH_ENVIRONMENT] = $binary;
        if ($apiKey !== null) {
            $launchEnvironment[Credential::API_KEY_ENVIRONMENT] = $apiKey;
        }
        return [[$binary, 'launch', '--session', $session, '--stdout-format', 'json'], $launchEnvironment];
    }

    /**
     * The code and message of the last error the launch command reported as
     * JSON: drain_required when the launch refused a new credential for a
     * client that has write locks, and launch_failed otherwise, also with the
     * whole output when the command reported no error as JSON.
     *
     * @return array{string, string}
     */
    public static function launchRefusal(string $output): array
    {
        $refusal = '';
        $refusalCode = ErrorCode::LAUNCH_FAILED;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $diagnostic = json_decode($line, true);
            // A warning carries an event; an error does not.
            if (!is_array($diagnostic) || array_key_exists('event', $diagnostic) || !is_string($diagnostic['code'] ?? null)) {
                continue;
            }
            $message = $diagnostic['message'] ?? null;
            if (!is_string($message) || $message === '') {
                continue;
            }
            $refusal = $message;
            $refusalCode = $diagnostic['code'] === ErrorCode::DRAIN_REQUIRED ? ErrorCode::DRAIN_REQUIRED : ErrorCode::LAUNCH_FAILED;
        }
        if ($refusal === '') {
            return [ErrorCode::LAUNCH_FAILED, trim($output)];
        }
        return [$refusalCode, $refusal];
    }

    public static function launchFailed(string $output, string $cause): LitebackupError
    {
        [$code, $refusal] = self::launchRefusal($output);
        return new LitebackupError($code, 'starting Litebackup failed: ' . ($refusal === '' ? $cause : $refusal));
    }

    /**
     * Starts the session daemon when nothing listens on its socket.
     *
     * @param array<string, string> $environment
     */
    public static function launchDaemon(
        string $session,
        string $socketPath,
        array $environment,
        ?string $apiKey,
        ?string $binaryPath,
    ): void {
        if (self::socketListening($socketPath)) {
            return;
        }
        [$command, $launchEnvironment] = self::launchCommand($session, $environment, $apiKey, $binaryPath);
        $launch = ChildCommand::start($command, $launchEnvironment, true);
        try {
            if (!$launch->awaitExit(self::LAUNCH_TIMEOUT_SECONDS)) {
                $launch->signal(ChildCommand::SIGNAL_KILL);
                $launch->awaitExit(null);
                throw self::launchFailed('', 'litebackup launch did not finish within ' . (int) self::LAUNCH_TIMEOUT_SECONDS . ' s');
            }
            $launch->drain(self::DRAIN_SECONDS);
            if ($launch->exitStatus() === 0) {
                return;
            }
            if ($launch->execFailed()) {
                throw new LitebackupError(ErrorCode::LAUNCH_FAILED, Binary::startFailureMessage($command[0], 'it could not be started'));
            }
            $errorOutput = $launch->errorOutput();
            $exit = $launch->exitStatus() ?? ('signal ' . $launch->terminationSignal());
            throw self::launchFailed($errorOutput === '' ? $launch->output() : $errorOutput, 'exit status ' . $exit);
        } finally {
            $launch->close();
        }
    }

    /**
     * The environment a client resolves paths and starts the daemon with:
     * this process's by default.
     *
     * @param array<array-key, mixed>|null $environment
     * @return array<string, string>
     */
    public static function resolveEnvironment(?array $environment): array
    {
        $source = $environment ?? getenv();
        $resolved = [];
        foreach ($source as $variable => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw LitebackupError::invalidArgument('environment values must be strings; ' . $variable . ' is ' . get_debug_type($value));
            }
            $resolved[(string) $variable] = (string) $value;
        }
        return $resolved;
    }
}
