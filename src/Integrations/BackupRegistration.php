<?php

declare(strict_types=1);

namespace Litebackup\Integrations;

use Litebackup\Client;
use Litebackup\Generated\BackupRule;
use Litebackup\LitebackupError;

/**
 * Registering a framework's SQLite databases as continuous backups, which
 * the Laravel provider and the Symfony bundle share. A registration starts
 * each database's backup with backups->start(), which returns the saved
 * rule when the same settings were registered before, so every deploy and
 * every worker start registers again safely; changed settings throw
 * rule_exists. It never shuts the daemon down.
 *
 * @internal
 */
final class BackupRegistration
{
    /** The backups->start() options a connection may set, as configuration spells them. */
    public const START_KEYS = ['name', 'prefix', 'maximum_local_databases', 'offload_durability', 'offload_rollback_journal'];
    /** The client options the configuration may set. */
    public const CLIENT_KEYS = ['api_key', 'session', 'socket_path', 'binary_path'];

    /**
     * A connection's start options as named arguments of backups->start().
     *
     * @return array<string, mixed>
     */
    public static function startOptions(string $connection, mixed $options): array
    {
        if ($options === null) {
            return [];
        }
        if (!is_array($options)) {
            throw new ConfigurationError('litebackup.connections.' . $connection . ' must be a map of backups->start() options');
        }
        $unknown = array_diff(array_map('strval', array_keys($options)), self::START_KEYS);
        if ($unknown !== []) {
            sort($unknown);
            throw new ConfigurationError('litebackup.connections.' . $connection . ' has unknown keys: ' . implode(', ', $unknown));
        }
        return self::namedArguments($options);
    }

    /**
     * The client options as named arguments of Client::__construct(), the
     * unset ones left out.
     *
     * @param array<array-key, mixed> $settings
     * @return array<string, mixed>
     */
    public static function clientOptions(array $settings): array
    {
        $options = [];
        foreach (self::CLIENT_KEYS as $key) {
            if (($settings[$key] ?? null) !== null) {
                $options[$key] = $settings[$key];
            }
        }
        return self::namedArguments($options);
    }

    /**
     * Before the first migration there is no file yet. An empty file is the
     * empty database SQLite would create on first connect, and the daemon
     * backs it up from its first write. It is created only when its
     * directory exists, and never over a file another process created first.
     */
    public static function createEmptyDatabase(string $connection, string $path): void
    {
        if (!is_dir(dirname($path))) {
            throw new ConfigurationError(
                'litebackup.connections.' . $connection . ': the directory of ' . $path . ' does not exist',
            );
        }
        $created = @fopen($path, 'x');
        if ($created !== false) {
            fclose($created);
        }
    }

    /**
     * Starts the backup of each database with a client that closes once they are saved.
     *
     * @param array<string, mixed> $clientOptions named arguments of Client::__construct()
     * @param list<array{string, array<string, mixed>}> $registrations each database file and its start options
     * @return list<BackupRule>
     */
    public static function register(array $clientOptions, array $registrations): array
    {
        try {
            // Configuration values are checked by the client, which throws invalid_argument.
            $client = new Client(...$clientOptions); // @phpstan-ignore argument.type
        } catch (LitebackupError $error) {
            throw new ConfigurationError('litebackup: ' . $error->describe(), 0, $error);
        }
        $rules = [];
        try {
            foreach ($registrations as [$path, $startOptions]) {
                try {
                    $rules[] = $client->backups->start($path, ...$startOptions); // @phpstan-ignore argument.type
                } catch (LitebackupError $error) {
                    throw new ConfigurationError('registering the backup of ' . $path . ' failed: ' . $error->describe(), 0, $error);
                }
            }
        } finally {
            $client->close();
        }
        return $rules;
    }

    /**
     * snake_case keys as camelCase named arguments.
     *
     * @param array<array-key, mixed> $options
     * @return array<string, mixed>
     */
    private static function namedArguments(array $options): array
    {
        $named = [];
        foreach ($options as $key => $value) {
            $named[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $key))))] = $value;
        }
        return $named;
    }
}
