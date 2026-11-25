<?php

declare(strict_types=1);

namespace Litebackup\Tests\Frameworks\Symfony;

use Litebackup\Tests\Support\DaemonTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * A test of LitebackupBundle in a BackupsKernel whose project directory is
 * the test's working directory, with a fake daemon behind socket_path.
 */
abstract class BundleTestCase extends DaemonTestCase
{
    /** @var list<BackupsKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        parent::tearDown();
    }

    /** The file of the default connection, in var/, which does not exist before a test creates it. */
    protected function databasePath(): string
    {
        return $this->workingDirectory . '/var/app.sqlite';
    }

    /**
     * A booted app with these litebackup settings and connections, by default
     * one SQLite connection named default.
     *
     * @param array<string, mixed> $litebackupSettings
     * @param array<string, array<string, mixed>>|null $connections
     */
    protected function bootApp(array $litebackupSettings, ?array $connections = null, string $environment = 'prod'): BackupsKernel
    {
        if (!is_dir($this->workingDirectory . '/var')) {
            mkdir($this->workingDirectory . '/var', 0o755);
        }
        $connections ??= ['default' => ['driver' => 'pdo_sqlite', 'path' => $this->databasePath()]];
        $kernel = new BackupsKernel($this->workingDirectory, $environment, $litebackupSettings, $connections);
        $kernel->boot();
        $this->kernels[] = $kernel;
        return $kernel;
    }

    /**
     * Runs one bin/console command in the app, without interaction.
     *
     * @param array<string, mixed> $input the command name under 'command', then its arguments and options
     */
    protected static function console(BackupsKernel $kernel, array $input): ApplicationTester
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => false]);
        return $tester;
    }

    /**
     * A backup rule as the daemon saves it.
     *
     * @return array<string, mixed>
     */
    protected static function savedRule(string $path): array
    {
        return [
            'id' => 'rule-1',
            'path' => $path,
            'name' => 'app/main.sqlite',
            'prefix' => '',
            'watch' => 'inotify',
            'maximum_local_databases' => 0,
            'offload_durability' => 'server',
            'offload_rollback_journal' => false,
            'failover' => false,
        ];
    }
}
