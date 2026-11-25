<?php

declare(strict_types=1);

namespace Litebackup\Tests\Frameworks\Laravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Litebackup\Integrations\Laravel\DatabaseBackups;
use Litebackup\Integrations\Laravel\LitebackupServiceProvider;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The Laravel provider in a real app of the lane's Laravel version:
 * litebackup:start registers the configured connections, workers register
 * once when they start, and nothing else calls the daemon.
 */
final class LaravelLaneTest extends TestCase
{
    private FakeDaemon $daemon;
    private string $directory = '';

    /** @return array<string, mixed> */
    private static function savedRule(string $path): array
    {
        return ['id' => 'rule-1', 'path' => $path, 'name' => 'app/main.sqlite'];
    }

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/litebackup-laravel-' . bin2hex(random_bytes(4));
        mkdir($directory . '/database', 0o755, true);
        $this->directory = (string) realpath($directory);
        $this->daemon = new FakeDaemon([
            FakeDaemon::json(['path' => '/v1/backup-rules/start'], self::savedRule($this->directory . '/database/main.sqlite'), 200, ['uses' => 0]),
        ]);
        (new ReflectionProperty(DatabaseBackups::class, 'registered'))->setValue(null, false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->daemon->close();
        DaemonTestCase::removeTree($this->directory);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LitebackupServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['env'] = 'production';
        $configuration = $app->make('config');
        self::assertInstanceOf(Repository::class, $configuration);
        $configuration->set('database.default', 'sqlite');
        $configuration->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->directory . '/database/main.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $configuration->set('database.connections.mysql', ['driver' => 'mysql', 'database' => 'app']);
        $configuration->set('database.connections.memory', ['driver' => 'sqlite', 'database' => 'file:app?mode=memory&cache=shared']);
        $configuration->set('database.connections.nowhere', ['driver' => 'sqlite', 'database' => $this->directory . '/missing/main.sqlite']);
        $configuration->set('database.connections.split', [
            'driver' => 'sqlite',
            'database' => $this->directory . '/database/main.sqlite',
            'read' => [['database' => $this->directory . '/database/copy.sqlite']],
        ]);
        $configuration->set('queue.default', 'sync');
        $configuration->set('litebackup.connections', ['sqlite' => ['name' => 'app/main.sqlite']]);
        $configuration->set('litebackup.socket_path', $this->daemon->socketPath);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/', static fn(): string => 'ok');
    }

    /** @return list<array<string, mixed>> */
    private function startRequests(): array
    {
        return array_values(array_map(
            static fn(array $request): array => DaemonTestCase::requestBody($request),
            array_filter($this->daemon->requests(), static fn(array $request): bool => $request['path'] === '/v1/backup-rules/start'),
        ));
    }

    /**
     * An artisan command to run and check, as Testbench's artisan() returns it.
     *
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);
        return $pending;
    }

    private function events(): Dispatcher
    {
        $events = $this->app?->make('events');
        self::assertInstanceOf(Dispatcher::class, $events);
        return $events;
    }

    private function setLitebackup(string $key, mixed $value): void
    {
        $configuration = $this->app?->make('config');
        self::assertInstanceOf(Repository::class, $configuration);
        $configuration->set('litebackup.' . $key, $value);
    }

    public function testStartRegistersTheConnectionAndCreatesItsFile(): void
    {
        $this->command('litebackup:start')
            ->expectsOutputToContain('Backing up ' . $this->directory . '/database/main.sqlite')
            ->assertSuccessful();
        self::assertSame([['path' => $this->directory . '/database/main.sqlite', 'name' => 'app/main.sqlite']], $this->startRequests());
        self::assertFileExists($this->directory . '/database/main.sqlite');
        self::assertSame(0, filesize($this->directory . '/database/main.sqlite'));
    }

    public function testSecondStartRegistersTheSameRuleAgain(): void
    {
        $this->command('litebackup:start')->assertSuccessful();
        $this->command('litebackup:start')->assertSuccessful();
        self::assertCount(2, $this->startRequests());
        self::assertSame($this->startRequests()[0], $this->startRequests()[1]);
    }

    public function testChangedSettingsFailWithRuleExists(): void
    {
        $changed = new FakeDaemon([FakeDaemon::json(
            ['path' => '/v1/backup-rules/start'],
            ['code' => 'rule_exists', 'message' => 'the path has a rule with other settings', 'rule' => self::savedRule('/x')],
            409,
        )]);
        try {
            $this->setLitebackup('socket_path', $changed->socketPath);
            $this->command('litebackup:start')->expectsOutputToContain('rule_exists')->assertFailed();
        } finally {
            $changed->close();
        }
    }

    public function testWorkersRegisterOnceWhenTheyStart(): void
    {
        $this->command('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        $this->command('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        self::assertCount(1, $this->startRequests());
        $this->events()->dispatch(LitebackupServiceProvider::OCTANE_WORKER_STARTING);
        self::assertCount(1, $this->startRequests());
    }

    public function testOctaneWorkerRegistersWhenItBoots(): void
    {
        $this->events()->dispatch(LitebackupServiceProvider::OCTANE_WORKER_STARTING);
        self::assertCount(1, $this->startRequests());
    }

    public function testRequestsMigrationsAndPlainCommandsNeverCallTheDaemon(): void
    {
        touch($this->directory . '/database/main.sqlite');
        $this->get('/')->assertOk()->assertSee('ok');
        $this->command('migrate', ['--force' => true])->assertSuccessful();
        $this->command('cache:clear')->assertSuccessful();
        $this->command('env')->assertSuccessful();
        self::assertSame([], $this->daemon->requests());
    }

    public function testTestingEnvironmentIsNeverBackedUp(): void
    {
        $this->app?->detectEnvironment(static fn(): string => 'testing');
        $this->events()->dispatch(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput()));
        self::assertSame([], $this->daemon->requests());
        $this->command('litebackup:start')->expectsOutputToContain('testing environment')->assertFailed();
        self::assertSame([], $this->daemon->requests());
    }

    /** @return array<string, array{string, string}> */
    public static function deniedConnections(): array
    {
        return [
            'an unknown name' => ['absent', 'is not a database connection'],
            'another driver' => ['mysql', 'only SQLite files are backed up'],
            'a memory database' => ['memory', 'in-memory'],
            'a missing directory' => ['nowhere', 'does not exist'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedConnections')]
    public function testConnectionIsDenied(string $connection, string $reason): void
    {
        $this->setLitebackup('connections', [$connection => []]);
        $this->command('litebackup:start')->expectsOutputToContain($reason)->assertFailed();
        self::assertSame([], $this->daemon->requests());
    }

    public function testReadVariantIsBackedUpBesideTheWriteDatabase(): void
    {
        $this->setLitebackup('connections', ['split' => []]);
        $this->command('litebackup:start')->assertSuccessful();
        self::assertSame(
            [$this->directory . '/database/main.sqlite', $this->directory . '/database/copy.sqlite'],
            array_column($this->startRequests(), 'path'),
        );
    }
}
