<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Litebackup\Generated\BackupRule;
use Litebackup\Integrations\BackupRegistration;
use Litebackup\Integrations\ConfigurationError;
use Litebackup\Integrations\SqliteFile;
use Litebackup\LitebackupError;

/**
 * The continuous backups of the database connections config/litebackup.php
 * names. Each connection resolves as Laravel's DatabaseManager resolves it,
 * its url and read and write variants included, and a relative path
 * against the app's base path; a connection that is not SQLite, any memory
 * database Laravel's SQLiteConnector accepts, a missing directory, and the
 * testing environment are refused.
 */
final class DatabaseBackups
{
    /** The worker commands that register the backups once when they start. */
    public const WORKER_COMMANDS = ['queue:work', 'horizon:work', 'schedule:work'];

    /** Whether this PHP process registered the backups already. */
    private static bool $registered = false;

    public function __construct(private readonly Application $app) {}

    /** Whether workers register the backups: the enabled setting, or yes outside the testing environment. */
    public function enabled(): bool
    {
        $enabled = $this->setting('enabled');
        if ($enabled !== null) {
            return $enabled === true;
        }
        return !$this->app->environment('testing') && $this->connections() !== [];
    }

    /**
     * Registers every configured connection's database and returns the rules.
     *
     * @return list<BackupRule>
     */
    public function register(): array
    {
        if ($this->app->environment('testing')) {
            throw new ConfigurationError('litebackup: the testing environment is not backed up');
        }
        $registrations = [];
        foreach ($this->connections() as $connection => $options) {
            $startOptions = BackupRegistration::startOptions($connection, $options);
            foreach ($this->connectionFiles($connection) as $path) {
                BackupRegistration::createEmptyDatabase($connection, $path);
                $registrations[] = [$path, $startOptions];
            }
        }
        $settings = $this->configuration()->get('litebackup', []);
        $rules = BackupRegistration::register(BackupRegistration::clientOptions(is_array($settings) ? $settings : []), $registrations);
        self::$registered = true;
        return $rules;
    }

    /** Registers the backups unless this PHP process did already, as a worker does when it starts. */
    public function registerOnce(): void
    {
        if (self::$registered || !$this->enabled()) {
            return;
        }
        $this->register();
    }

    /**
     * The files a database connection writes: its database, and the
     * databases of its read and write variants, each once.
     *
     * @return list<string>
     */
    public function connectionFiles(string $connection): array
    {
        $configured = $this->configuration()->get('database.connections.' . $connection);
        if (!is_array($configured)) {
            throw new ConfigurationError('litebackup.connections: ' . $connection . ' is not a database connection of this app');
        }
        $config = (new ConfigurationUrlParser())->parseConfiguration($configured);
        $variants = [$config];
        foreach (['write', 'read'] as $type) {
            $variant = $config[$type] ?? null;
            if (!is_array($variant)) {
                continue;
            }
            foreach (array_is_list($variant) ? $variant : [$variant] as $listed) {
                if (is_array($listed)) {
                    $variants[] = array_merge($config, $listed);
                }
            }
        }
        $files = [];
        foreach ($variants as $variant) {
            if (($variant['driver'] ?? null) !== 'sqlite') {
                throw new ConfigurationError(
                    'litebackup.connections: ' . $connection . ' uses the ' . json_encode($variant['driver'] ?? null)
                    . ' driver; only SQLite files are backed up',
                );
            }
            $database = $variant['database'] ?? '';
            if (!is_string($database)) {
                throw new ConfigurationError('litebackup.connections: ' . $connection . ' has no database path');
            }
            if ($database === ':memory:' || str_contains($database, '?mode=memory') || str_contains($database, '&mode=memory')) {
                throw new ConfigurationError('litebackup.connections: ' . $connection . ': ' . SqliteFile::NO_FILE);
            }
            try {
                $files[] = SqliteFile::databaseFile($database, $this->app->basePath());
            } catch (LitebackupError $error) {
                throw new ConfigurationError('litebackup.connections: ' . $connection . ': ' . $error->getMessage(), 0, $error);
            }
        }
        return array_values(array_unique($files));
    }

    /** @return array<string, mixed> the configured connections and their options */
    private function connections(): array
    {
        $connections = $this->setting('connections') ?? [];
        if (!is_array($connections)) {
            throw new ConfigurationError('litebackup.connections must map connection names to backups->start() options');
        }
        $named = [];
        foreach ($connections as $connection => $options) {
            $named[(string) $connection] = $options;
        }
        return $named;
    }

    private function configuration(): Repository
    {
        $configuration = $this->app->make('config');
        if (!$configuration instanceof Repository) {
            throw new ConfigurationError('litebackup: the app has no configuration repository');
        }
        return $configuration;
    }

    private function setting(string $key): mixed
    {
        return $this->configuration()->get('litebackup.' . $key);
    }
}
