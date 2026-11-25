<?php

declare(strict_types=1);

namespace Litebackup\Tests\Frameworks\Symfony;

use Litebackup\Tests\Support\FakeDaemon;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;

/** bin/console litebackup:start registers the configured connections, or refuses them. */
final class StartCommandTest extends BundleTestCase
{
    public function testStartRegistersTheConnectionFileAndCreatesItEmpty(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/backup-rules/start'], self::savedRule($this->databasePath()), 200, ['uses' => 0]),
        ]);
        $kernel = $this->bootApp([
            'socket_path' => $daemon->socketPath,
            'connections' => ['default' => ['name' => 'app/main.sqlite', 'maximum_local_databases' => 3, 'offload_durability' => 'cloud']],
        ]);
        self::assertFileDoesNotExist($this->databasePath());

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($this->databasePath() . "\n", $tester->getDisplay());
        self::assertFileExists($this->databasePath());
        self::assertSame(0, filesize($this->databasePath()));
        $requests = $daemon->requests();
        self::assertCount(1, $requests);
        self::assertSame(['POST', '/v1/backup-rules/start'], [$requests[0]['method'], $requests[0]['path']]);
        self::assertSame(
            [
                'path' => $this->databasePath(),
                'name' => 'app/main.sqlite',
                'maximum_local_databases' => 3,
                'offload_durability' => 'cloud',
            ],
            self::requestBody($requests[0]),
        );
    }

    public function testStartAgainWithTheSameSettingsSucceeds(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/backup-rules/start'], self::savedRule($this->databasePath()), 200, ['uses' => 0]),
        ]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['default' => null]]);
        file_put_contents($this->databasePath(), 'existing');

        $first = self::console($kernel, ['command' => 'litebackup:start']);
        $second = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getDisplay());
        self::assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
        self::assertSame('existing', file_get_contents($this->databasePath()));
        $bodies = $daemon->bodies();
        self::assertCount(2, $bodies);
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(['path' => $this->databasePath()], $bodies[0]);
    }

    public function testChangedSettingsFailWithRuleExists(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(
                ['path' => '/v1/backup-rules/start'],
                ['code' => 'rule_exists', 'message' => 'a rule with other settings is saved for this path'],
                409,
            ),
        ]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['default' => ['prefix' => 'changed/']]]);

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'registering the backup of ' . $this->databasePath() . ' failed: rule_exists: a rule with other settings is saved for this path',
            $tester->getDisplay(),
        );
    }

    public function testAnUnknownConnectionIsDenied(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['missing' => null]]);

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('litebackup.connections.missing is not a Doctrine DBAL connection', $tester->getDisplay());
        self::assertSame([], $daemon->requests());
    }

    public function testAnotherDriverIsDenied(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(
            ['socket_path' => $daemon->socketPath, 'connections' => ['reporting' => null]],
            ['reporting' => ['driver' => 'pdo_mysql', 'host' => 'localhost', 'dbname' => 'app', 'server_version' => '8.0']],
        );

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'litebackup.connections.reporting uses the pdo_mysql driver; only SQLite files are backed up',
            $tester->getDisplay(),
        );
        self::assertSame([], $daemon->requests());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function memoryConnections(): array
    {
        return [
            'memory flag' => [['driver' => 'pdo_sqlite', 'memory' => true]],
            'memory path' => [['driver' => 'sqlite3', 'path' => ':memory:']],
            'memory URI' => [['driver' => 'pdo_sqlite', 'path' => 'file:cache?mode=memory']],
        ];
    }

    /** @param array<string, mixed> $connectionSettings */
    #[DataProvider('memoryConnections')]
    public function testMemoryDatabasesAreDenied(array $connectionSettings): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(
            ['socket_path' => $daemon->socketPath, 'connections' => ['cache' => null]],
            ['cache' => $connectionSettings],
        );

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'litebackup.connections.cache: an in-memory or temporary database has no file to back up',
            $tester->getDisplay(),
        );
        self::assertSame([], $daemon->requests());
    }

    public function testAMissingDirectoryIsDenied(): void
    {
        $daemon = $this->startDaemon([]);
        $path = $this->workingDirectory . '/absent/app.sqlite';
        $kernel = $this->bootApp(
            ['socket_path' => $daemon->socketPath, 'connections' => ['default' => null]],
            ['default' => ['driver' => 'pdo_sqlite', 'path' => $path]],
        );

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'litebackup.connections.default: the directory of ' . $path . ' does not exist',
            $tester->getDisplay(),
        );
        self::assertSame([], $daemon->requests());
    }

    public function testTheTestEnvironmentRegistersNothing(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['default' => null]], null, 'test');

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("litebackup.enabled is false; no backups were registered\n", $tester->getDisplay());
        self::assertFileDoesNotExist($this->databasePath());
        self::assertSame([], $daemon->requests());
    }

    public function testAnEnabledTestEnvironmentIsDenied(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(
            ['enabled' => true, 'socket_path' => $daemon->socketPath, 'connections' => ['default' => null]],
            null,
            'test',
        );

        $tester = self::console($kernel, ['command' => 'litebackup:start']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('litebackup.connections.default is a test database, which is not backed up', $tester->getDisplay());
        self::assertSame([], $daemon->requests());
    }
}
