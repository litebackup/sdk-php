<?php

declare(strict_types=1);

namespace Litebackup;

/** Session names and sockets, resolved exactly as the litebackup command does. */
final class Paths
{
    /** Records a litebackup install service install; root and the service's user use its socket. */
    public const SYSTEM_CONFIGURATION_PATH = '/etc/litebackup/client.json';
    public const DEFAULT_SESSION = 'default';
    private const SESSION_PATTERN = '/\A[a-z0-9][a-z0-9._-]*\z/';

    /**
     * The runtime directory and uid a service configuration file names, or null.
     *
     * @return array{string, ?int}|null
     */
    public static function runtimeDirectory(string $path): ?array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            return null;
        }
        $configuration = json_decode($text, true);
        if (!is_array($configuration)) {
            return null;
        }
        $directory = $configuration['runtime_directory'] ?? null;
        if (!is_string($directory) || $directory === '') {
            return null;
        }
        $uid = $configuration['uid'] ?? null;
        return [$directory, is_int($uid) ? $uid : null];
    }

    /**
     * The session name and its socket path.
     *
     * Where litebackup install service ran as root, root and the service's
     * user use the service's socket; where a user installed a service of
     * their own, that user uses the directory it saved. Everyone else uses
     * $XDG_RUNTIME_DIR/litebackup/<session>/client.sock, or a per-user
     * directory under the temporary directory, which must be this user's alone.
     *
     * @param array<string, string> $environment
     * @return array{string, string}
     */
    public static function sessionSocket(
        ?string $session,
        array $environment,
        string $systemConfiguration = self::SYSTEM_CONFIGURATION_PATH,
    ): array {
        $name = self::DEFAULT_SESSION;
        foreach ([$session, $environment['LITEBACKUP_SESSION'] ?? null] as $candidate) {
            if (Wire::supplied($candidate)) {
                $name = (string) $candidate;
                break;
            }
        }
        if (preg_match(self::SESSION_PATTERN, $name) !== 1) {
            throw LitebackupError::invalidArgument(
                'session names use [a-z0-9._-] and start with a letter or digit, got ' . json_encode($name),
            );
        }
        $uid = self::userID();
        $system = self::runtimeDirectory($systemConfiguration);
        if ($system !== null && ($uid === 0 || $uid === $system[1])) {
            return [$name, $system[0] . '/' . $name . '/client.sock'];
        }
        $home = Wire::supplied($environment['HOME'] ?? null) ? $environment['HOME'] : self::homeDirectory();
        $user = self::runtimeDirectory($home . '/.config/litebackup/service.json');
        if ($user !== null) {
            return [$name, $user[0] . '/' . $name . '/client.sock'];
        }
        $runtimeRoot = $environment['XDG_RUNTIME_DIR'] ?? '';
        if ($runtimeRoot === '') {
            $temporary = Wire::supplied($environment['TMPDIR'] ?? null)
                ? $environment['TMPDIR']
                : sys_get_temp_dir();
            $runtimeRoot = $temporary . '/litebackup-' . $uid;
            self::privateRuntimeDirectory($runtimeRoot, $uid);
        }
        return [$name, $runtimeRoot . '/litebackup/' . $name . '/client.sock'];
    }

    /**
     * Makes sure the per-user runtime directory used without
     * $XDG_RUNTIME_DIR is this user's own. The temporary directory is shared,
     * so another local user could create it first and answer as the daemon.
     * It is created with mode 0700 when missing; one that is a symlink, is
     * not a directory, belongs to another user, or is open to anyone else is
     * refused, as the litebackup command refuses it.
     */
    public static function privateRuntimeDirectory(string $directory, int $uid): void
    {
        if (!@mkdir($directory, 0o700) && !file_exists($directory) && !is_link($directory)) {
            $failure = error_get_last()['message'] ?? 'unknown error';
            throw LitebackupError::invalidArgument('creating ' . $directory . ' failed: ' . $failure);
        }
        clearstatcache(true, $directory);
        $status = @lstat($directory);
        if (
            $status !== false
            && ($status['mode'] & 0o170000) === 0o040000
            && $status['uid'] === $uid
            && ($status['mode'] & 0o077) === 0
        ) {
            return;
        }
        throw LitebackupError::invalidArgument(
            $directory . " is not a directory of this user's alone; "
            . "remove it or set XDG_RUNTIME_DIR to this user's own directory",
        );
    }

    /** This process's user ID: posix_getuid when the posix extension is loaded, else the owner of a new file. */
    public static function userID(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }
        $probe = tempnam(sys_get_temp_dir(), 'litebackup-uid-');
        if ($probe === false) {
            return getmyuid() === false ? 0 : getmyuid();
        }
        $owner = fileowner($probe);
        @unlink($probe);
        return $owner === false ? 0 : $owner;
    }

    /** The home directory without $HOME: the password database's, through posix. */
    public static function homeDirectory(): string
    {
        if (function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(self::userID());
            if ($entry !== false && $entry['dir'] !== '') {
                return $entry['dir'];
            }
        }
        return '/';
    }
}
