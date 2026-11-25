<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Litebackup\Generated\BackupRule;
use Litebackup\Integrations\BackupRegistration;
use Litebackup\Integrations\ConfigurationError;
use Litebackup\Integrations\SqliteFile;
use Litebackup\LitebackupError;

/**
 * Starts the backups of the Doctrine DBAL connections litebackup.connections
 * names. It reads each connection's parameters, which does not connect,
 * and refuses connections that are not SQLite files: other drivers,
 * in-memory databases, and the test environment's databases. A relative
 * path is resolved against the working directory, as SQLite opens it.
 */
final class DoctrineBackups
{
    /** The DBAL drivers whose databases are SQLite files. */
    public const SQLITE_DRIVERS = ['pdo_sqlite', 'sqlite3'];

    /**
     * @param array<array-key, mixed> $connections each connection name and its backups->start() options
     * @param array<array-key, mixed> $clientSettings api_key, session, socket_path, and binary_path
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly array $connections,
        private readonly array $clientSettings,
        private readonly string $environment,
        public readonly bool $enabled,
    ) {}

    /** @return list<string> the configured connection names */
    public function connectionNames(): array
    {
        return array_map('strval', array_keys($this->connections));
    }

    /**
     * Starts the backup of each configured connection's file; returns the saved rules.
     *
     * @return list<BackupRule>
     */
    public function register(): array
    {
        $registrations = [];
        foreach ($this->connections as $connectionName => $options) {
            $connectionName = (string) $connectionName;
            $registrations[] = [
                $this->databaseFile($connectionName),
                BackupRegistration::startOptions($connectionName, $options),
            ];
        }
        if ($registrations === []) {
            return [];
        }
        return BackupRegistration::register(BackupRegistration::clientOptions($this->clientSettings), $registrations);
    }

    /**
     * The file a connection opens, created empty when it does not exist yet.
     */
    private function databaseFile(string $connectionName): string
    {
        $setting = 'litebackup.connections.' . $connectionName;
        if (!array_key_exists($connectionName, $this->doctrine->getConnectionNames())) {
            throw new ConfigurationError($setting . ' is not a Doctrine DBAL connection');
        }
        $connection = $this->doctrine->getConnection($connectionName);
        if (!$connection instanceof Connection) {
            throw new ConfigurationError($setting . ' is not a Doctrine DBAL connection');
        }
        $params = $connection->getParams();
        $driver = $params['driver'] ?? null;
        if (!in_array($driver, self::SQLITE_DRIVERS, true)) {
            throw new ConfigurationError(
                $setting . ' uses the ' . ($driver ?? 'unnamed') . ' driver; only SQLite files are backed up',
            );
        }
        if (($params['memory'] ?? false) === true) {
            throw new ConfigurationError($setting . ': ' . SqliteFile::NO_FILE);
        }
        if ($this->environment === 'test') {
            throw new ConfigurationError($setting . ' is a test database, which is not backed up');
        }
        try {
            $path = SqliteFile::databaseFile($params['path'] ?? '', (string) getcwd());
        } catch (LitebackupError $error) {
            throw new ConfigurationError($setting . ': ' . $error->getMessage(), 0, $error);
        }
        BackupRegistration::createEmptyDatabase($connectionName, $path);
        return $path;
    }
}
