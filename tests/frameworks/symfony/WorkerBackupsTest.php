<?php

declare(strict_types=1);

namespace Litebackup\Tests\Frameworks\Symfony;

use Litebackup\Tests\Support\FakeDaemon;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;

/**
 * A messenger:consume worker registers the backups once before it consumes,
 * and nothing else the app does calls the daemon. The worker tests run real
 * workers on the in-memory transport, each in a PHP process of its own,
 * because registration happens once per process.
 */
final class WorkerBackupsTest extends BundleTestCase
{
    #[RunInSeparateProcess]
    public function testAWorkerRegistersOnceWhenItStarts(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/backup-rules/start'], self::savedRule($this->databasePath()), 200, ['uses' => 0]),
        ]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['default' => null]]);
        $workerInput = ['command' => 'messenger:consume', 'receivers' => ['memory'], '--limit' => '1', '--time-limit' => '1'];

        $first = self::console($kernel, $workerInput);
        $second = self::console($kernel, $workerInput);

        self::assertSame(Command::SUCCESS, $first->getStatusCode(), $first->getDisplay());
        self::assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
        self::assertSame(['/v1/backup-rules/start'], array_column($daemon->requests(), 'path'));
        self::assertFileExists($this->databasePath());
    }

    #[RunInSeparateProcess]
    public function testAWorkerStopsWhenRegistrationFails(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['missing' => null]]);

        $tester = self::console($kernel, ['command' => 'messenger:consume', 'receivers' => ['memory'], '--time-limit' => '1']);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('litebackup.connections.missing is not a Doctrine DBAL connection', $tester->getDisplay());
        self::assertSame([], $daemon->requests());
    }

    #[RunInSeparateProcess]
    public function testADisabledWorkerRegistersNothing(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(['enabled' => false, 'socket_path' => $daemon->socketPath, 'connections' => ['default' => null]]);

        $tester = self::console($kernel, ['command' => 'messenger:consume', 'receivers' => ['memory'], '--time-limit' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $daemon->requests());
    }

    public function testRequestsAndOrdinaryCommandsNeverCallTheDaemon(): void
    {
        $daemon = $this->startDaemon([]);
        $kernel = $this->bootApp(['socket_path' => $daemon->socketPath, 'connections' => ['default' => null]]);

        $request = Request::create('/ping');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $about = self::console($kernel, ['command' => 'about']);
        $cacheClear = self::console($kernel, ['command' => 'cache:clear']);

        self::assertSame('pong', $response->getContent());
        self::assertSame(Command::SUCCESS, $about->getStatusCode(), $about->getDisplay());
        self::assertSame(Command::SUCCESS, $cacheClear->getStatusCode(), $cacheClear->getDisplay());
        self::assertSame([], $daemon->requests());
        self::assertFileDoesNotExist($this->databasePath());
    }
}
