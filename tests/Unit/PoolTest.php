<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\ErrorCode;
use Litebackup\LeaseCleanupError;
use Litebackup\LitebackupError;
use Litebackup\Pool;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use Litebackup\Tests\Support\StepAdapter;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/** Leases kept between calls, lost locks, cooperative idle release, rule databases, and the shutdown cleanup. */
final class PoolTest extends DaemonTestCase
{
    private const NAME = 'agents/42.sqlite';

    /** @return array<string, mixed> */
    private function acquired(string $name = self::NAME): array
    {
        return ['database' => [
            'name' => $name, 'path' => $this->workingDirectory . '/' . $name, 'role' => 'origin',
            'state' => 'synced', 'acquired' => true,
        ], 'decision' => 'created'];
    }

    /** @return array<string, mixed> */
    private static function released(): array
    {
        return ['database' => ['name' => self::NAME, 'path' => '/x', 'role' => 'origin', 'state' => 'synced']];
    }

    /**
     * Answers for acquire, release, and the status reads of kept leases.
     *
     * @param array<string, mixed> $status
     * @return list<array<string, mixed>>
     */
    private function leaseAnswers(array $status = ['acquired' => true, 'state' => 'synced']): array
    {
        return [
            FakeDaemon::json(['path' => '/v1/databases/acquire'], $this->acquired(), 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/release'], self::released(), 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/status'], ['name' => self::NAME, 'path' => '/x', 'role' => 'origin'] + $status, 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/sync'], [
                'name' => self::NAME, 'path' => '/x', 'role' => 'origin', 'state' => 'synced',
                'synced_server' => ['version' => 3], 'synced_cloud' => ['version' => 3],
            ], 200, ['uses' => 0]),
        ];
    }

    /** @return list<string> */
    private static function paths(FakeDaemon $daemon): array
    {
        return array_map(static fn(array $request): string => substr($request['path'], strlen('/v1/databases/')), $daemon->requests());
    }

    public function testLeaseKeepsItsLockBetweenCalls(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $adapter = new StepAdapter([]);
        $pool = $this->clientTo($daemon)->databases->pool($adapter);
        $handles = [];
        foreach ([1, 2] as $call) {
            self::assertSame($call, $pool->lease(self::NAME, function (object $handle) use (&$handles, $call): int {
                $handles[] = $handle;
                return $call;
            }, path: self::NAME));
        }
        self::assertSame($handles[0], $handles[1]);
        unset($handles);
        $pool->close();
        self::assertSame(['acquire', 'status', 'release'], self::paths($daemon));
        self::assertFileExists($this->workingDirectory . '/' . self::NAME);
    }

    public function testLostLockThrowsLockLostAndTheNextLeaseAcquiresAgain(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers(['acquired' => false, 'state' => 'waiting']));
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
        $pool->lease(self::NAME, static fn(object $handle): null => null);
        $called = false;
        try {
            $pool->lease(self::NAME, static function (object $handle) use (&$called): void {
                $called = true;
            });
            self::fail('the lease ran');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::LOCK_LOST, $error->errorCode);
        }
        self::assertFalse($called);
        $pool->lease(self::NAME, static fn(object $handle): null => null);
        $pool->close();
        self::assertSame(['acquire', 'status', 'acquire', 'release'], self::paths($daemon));
    }

    public function testIdleReleaseIsCooperative(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]), idleRelease: 0.2);
        $pool->lease(self::NAME, static fn(object $handle): null => null);
        usleep(300_000);
        // No call came, so nothing was released.
        self::assertSame(['acquire'], self::paths($daemon));
        $pool->lease('other.sqlite', static fn(object $handle): null => null);
        self::assertSame(['acquire', 'release', 'acquire'], self::paths($daemon));
        $pool->close();
    }

    public function testIdleReleaseFailureReachesTheCallbackAfterTheCall(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/acquire'], $this->acquired(), 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/release'], ['code' => 'still_open', 'message' => 'a process has it open'], 409),
            FakeDaemon::json(['path' => '/v1/databases/release'], self::released(), 200, ['uses' => 0]),
        ]);
        $reported = [];
        $pool = null;
        $pool = $this->clientTo($daemon)->databases->pool(
            new StepAdapter([]),
            idleRelease: 0.1,
            onReleaseError: function (LeaseCleanupError $error) use (&$reported, &$pool): void {
                $reported[] = $error->step;
                // The call is done, so the callback may release and close.
                $pool?->release($error->name);
                $pool?->close();
            },
        );
        $pool->lease(self::NAME, static fn(object $handle): null => null);
        usleep(200_000);
        $pool->lease('other.sqlite', static fn(object $handle): null => null);
        self::assertSame([LeaseCleanupError::STEP_RELEASE], $reported);
        self::assertSame(['acquire', 'release', 'acquire', 'release', 'release'], self::paths($daemon));
    }

    public function testCloseAndNestedLeasesAreDeniedInsideACall(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
        $pool->lease(self::NAME, function (object $handle) use ($pool): void {
            foreach ([
                static fn() => $pool->close(),
                static fn() => $pool->lease(self::NAME, static fn(object $inner): null => null),
                static fn() => $pool->release(self::NAME),
            ] as $denied) {
                try {
                    $denied();
                    self::fail('the call inside the lease ran');
                } catch (LitebackupError $error) {
                    self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
                }
            }
        });
        $pool->close();
        try {
            $pool->lease(self::NAME, static fn(object $handle): null => null);
            self::fail('a closed pool leased');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::CLOSED, $error->errorCode);
        }
    }

    public function testFailedWorkKeepsTheLeaseAndNeverOffloads(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
        try {
            $pool->lease(self::NAME, static function (object $handle): void {
                throw new \RuntimeException('work failed');
            }, offload: true);
        } catch (\RuntimeException) {
        }
        $pool->close();
        self::assertSame(['acquire', 'release'], self::paths($daemon));
        $release = self::requestBody($daemon->requests()[1]);
        self::assertArrayNotHasKey('offload', $release);
    }

    public function testCloudDurabilityWaitsUntilTheBucketHoldsTheVersion(): void
    {
        $previous = Pool::$cloudPollSeconds;
        Pool::$cloudPollSeconds = 0.01;
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/acquire'], $this->acquired(), 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/sync'], [
                'name' => self::NAME, 'path' => '/x', 'role' => 'origin', 'state' => 'synced',
                'synced_server' => ['version' => 5], 'synced_cloud' => ['version' => 3],
            ]),
            FakeDaemon::json(['path' => '/v1/databases/status'], ['name' => self::NAME, 'path' => '/x', 'role' => 'origin', 'synced_cloud' => ['version' => 4]]),
            FakeDaemon::json(['path' => '/v1/databases/status'], ['name' => self::NAME, 'path' => '/x', 'role' => 'origin', 'synced_cloud' => ['version' => 5]]),
            FakeDaemon::json(['path' => '/v1/databases/release'], self::released(), 200, ['uses' => 0]),
        ]);
        try {
            $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
            $pool->lease(self::NAME, static fn(object $handle): null => null, durability: 'cloud');
            $pool->close();
        } finally {
            Pool::$cloudPollSeconds = $previous;
        }
        self::assertSame(['acquire', 'sync', 'status', 'status', 'release'], self::paths($daemon));
    }

    public function testRuleDatabaseIsOpenedOnceAndBroughtBackWhenOffloaded(): void
    {
        $path = $this->workingDirectory . '/main.sqlite';
        touch($path);
        $status = ['name' => 'main.sqlite', 'path' => $path, 'role' => 'origin', 'backup_rule_id' => 'rule-1', 'journal_mode' => 'wal'];
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/status'], $status + ['state' => 'offloaded']),
            FakeDaemon::json(['path' => '/v1/databases/acquire'], ['database' => $status]),
            FakeDaemon::json(['path' => '/v1/backup-rules/list'], ['rules' => [['id' => 'rule-1', 'path' => $path, 'maximum_local_databases' => 5]]]),
            FakeDaemon::json(['path' => '/v1/databases/status'], ['name' => 'x.sqlite', 'path' => '/x', 'role' => 'origin', 'acquired' => true]),
        ]);
        $adapter = new StepAdapter([]);
        $pool = $this->clientTo($daemon)->databases->pool($adapter);
        $pool->use('main.sqlite', static fn(object $handle): null => null);
        $pool->use('main.sqlite', static fn(object $handle): null => null);
        try {
            $pool->use('x.sqlite', static fn(object $handle): null => null);
            self::fail('an acquired database was used');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
        $pool->close();
        self::assertSame(['open', 'close'], array_column($adapter->steps, 0));
        self::assertSame(
            ['/v1/databases/status', '/v1/databases/acquire', '/v1/backup-rules/list', '/v1/databases/status'],
            array_column($daemon->requests(), 'path'),
        );
    }

    public function testRollbackJournalDatabaseClosesAfterItsLastUse(): void
    {
        $path = $this->workingDirectory . '/main.sqlite';
        touch($path);
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/status'], ['name' => 'main.sqlite', 'path' => $path, 'role' => 'origin', 'state' => 'synced', 'backup_rule_id' => 'rule-1', 'journal_mode' => 'rollback'], 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/backup-rules/list'], ['rules' => [['id' => 'rule-1', 'path' => $path, 'offload_rollback_journal' => true]]]),
        ]);
        $adapter = new StepAdapter([]);
        $pool = $this->clientTo($daemon)->databases->pool($adapter);
        $pool->use('main.sqlite', function (object $handle) use ($pool, $adapter): void {
            $pool->use('main.sqlite', static fn(object $inner): null => null);
            // The outer use is still active, so the database stays open.
            self::assertSame(['open'], array_column($adapter->steps, 0));
        });
        self::assertSame(['open', 'close'], array_column($adapter->steps, 0));
        $pool->close();
    }

    #[RequiresPhpExtension('pcntl')]
    public function testPoolIsDeniedInAForkedChild(): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
        $reportPath = $this->workingDirectory . '/child.txt';
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $pool->lease(self::NAME, static fn(object $handle): null => null);
                file_put_contents($reportPath, 'leased');
            } catch (LitebackupError $error) {
                file_put_contents($reportPath, $error->errorCode);
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);
        self::assertSame(ErrorCode::INVALID_ARGUMENT, file_get_contents($reportPath));
        $pool->close();
        self::assertSame([], self::paths($daemon));
    }

    /**
     * A PHP process that ends inside a lease, through exit or a fatal error,
     * skips finally; its shutdown function releases the lease without
     * offload. One that finishes its call and exits releases with offload.
     *
     * With the PDO adapter, exit unwinds the work's frame, so the close
     * succeeds; after an execution-time fatal error PHP runs the shutdown
     * function with that frame alive, still holding the connection, so the
     * close fails and the lease stays acquired, as every failed close
     * leaves it. The SQLite3 adapter closes its database itself either way.
     *
     * @return array<string, array{string, bool, string, list<string>}>
     */
    public static function endings(): array
    {
        $steps = 'new Litebackup\\Tests\\Support\\StepAdapter([])';
        $pdo = 'Litebackup\\Integrations\\PdoSqlite::adapter()';
        $sqlite3 = 'Litebackup\\Integrations\\Sqlite3::adapter()';
        $exit = 'exit(0);';
        $timeLimit = 'set_time_limit(1); while (true) {}';
        $released = ['acquire', 'release'];
        return [
            'exit inside the call' => [$exit, false, $steps, $released],
            'a time limit inside the call' => [$timeLimit, false, $steps, $released],
            'exit after the call' => ['', true, $steps, $released],
            'exit inside a PDO call' => [$exit, false, $pdo, $released],
            'a time limit inside a PDO call' => [$timeLimit, false, $pdo, ['acquire']],
            'exit inside an SQLite3 call' => [$exit, false, $sqlite3, $released],
            'a time limit inside an SQLite3 call' => [$timeLimit, false, $sqlite3, $released],
        ];
    }

    /** @param list<string> $daemonCalls */
    #[\PHPUnit\Framework\Attributes\DataProvider('endings')]
    public function testShutdownReleasesALeaseAnEndingScriptLeft(string $ending, bool $offloads, string $adapter, array $daemonCalls): void
    {
        $daemon = $this->startDaemon($this->leaseAnswers());
        $script = $this->workingDirectory . '/lease.php';
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $support = var_export(dirname(__DIR__) . '/Support/StepAdapter.php', true);
        $socket = var_export($daemon->socketPath, true);
        $name = var_export(self::NAME, true);
        $inside = var_export($ending, true);
        file_put_contents($script, <<<PHP
            <?php
            require {$autoload};
            require {$support};
            \$client = new Litebackup\\Client(socketPath: {$socket});
            \$pool = \$client->databases->pool({$adapter});
            \$pool->lease({$name}, static function (object \$handle): void {
                eval({$inside});
            }, path: {$name}, offload: true);
            exit(0);
            PHP);
        $child = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($child);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($child);
        self::assertSame($daemonCalls, self::paths($daemon), $output);
        if (count($daemonCalls) === 2) {
            $release = self::requestBody($daemon->requests()[1]);
            self::assertSame($offloads, $release['offload'] ?? false);
        }
    }
    public function testFailedStatusCheckRevokesTheRetainedLeaseOffload(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/acquire'], $this->acquired(), 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/status'], ['code' => 'unavailable', 'message' => 'the daemon is busy'], 503),
            FakeDaemon::json(['path' => '/v1/databases/release'], self::released(), 200, ['uses' => 0]),
        ]);
        $pool = $this->clientTo($daemon)->databases->pool(new StepAdapter([]));
        $pool->lease(self::NAME, static fn(object $handle): null => null, offload: true);
        try {
            $pool->lease(self::NAME, static fn(object $handle): null => null, offload: true);
            self::fail('the lease ran');
        } catch (LitebackupError $error) {
            self::assertSame('unavailable', $error->errorCode);
        }
        $pool->close();
        self::assertSame(['acquire', 'status', 'release'], self::paths($daemon));
        self::assertArrayNotHasKey('offload', self::requestBody($daemon->requests()[2]));
    }
}
