<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Litebackup\Client;
use Litebackup\ErrorCode;
use Litebackup\Foreground;
use Litebackup\Launch;
use Litebackup\LitebackupError;
use Litebackup\Paths;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use Litebackup\Umask;
use Litebackup\Wire;
use Litebackup\WireModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Stringable;

/** The client's local checks, launches, foreground jobs, and life across a fork. */
final class ClientTest extends DaemonTestCase
{
    private const DATABASE = ['name' => 'main.sqlite', 'path' => '/x', 'role' => 'origin', 'state' => 'synced'];

    /** @return array<string, array{callable(Client): mixed}> */
    public static function wrongArguments(): array
    {
        return [
            'a number as a name' => [static fn(Client $client): mixed => $client->databases->get(42)], // @phpstan-ignore argument.type
            'text as a timeout' => [static fn(Client $client): mixed => $client->databases->acquire('a', timeout: '5s')], // @phpstan-ignore argument.type
            'a boolean as a timeout' => [static fn(Client $client): mixed => $client->databases->acquire('a', timeout: true)], // @phpstan-ignore argument.type
            'NAN as a timeout' => [static fn(Client $client): mixed => $client->databases->acquire('a', timeout: NAN)],
            'infinity as a lock timeout' => [static fn(Client $client): mixed => $client->databases->acquire('a', lockTimeout: INF)],
            'a negative release timeout' => [static fn(Client $client): mixed => $client->databases->release('a', timeout: -0.5)],
            'text as a flag' => [static fn(Client $client): mixed => $client->databases->acquire('a', maintenance: 'yes')], // @phpstan-ignore argument.type
            'a float as a umask' => [static fn(Client $client): mixed => $client->databases->restore('a', path: '/r', umask: 18.0)], // @phpstan-ignore argument.type
            'an array as a path' => [static fn(Client $client): mixed => $client->backups->start(['/a'])], // @phpstan-ignore argument.type
            'a zero page limit' => [static fn(Client $client): mixed => $client->databases->page(limit: 0)],
            'a float as a version' => [static fn(Client $client): mixed => $client->databases->revert('a', version: 1.0)], // @phpstan-ignore argument.type
            'a time without a zone' => [static fn(Client $client): mixed => $client->databases->restore('a', path: '/r', time: '2026-11-15T09:30:00')],
            'a lowercase durability' => [static fn(Client $client): mixed => $client->databases->offload('a', durability: 'Cloud')],
        ];
    }

    /** @param callable(Client): mixed $call */
    #[DataProvider('wrongArguments')]
    public function testWrongArgumentIsInvalidArgument(callable $call): void
    {
        $daemon = $this->startDaemon([]);
        $client = $this->clientTo($daemon);
        try {
            $call($client);
            self::fail('the call was sent');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
            self::assertSame(0, $error->status);
        }
        self::assertSame([], $daemon->requests());
    }

    public function testZeroUmaskAndCursorAreSent(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], ['database' => self::DATABASE, 'restored' => 1], 200, ['uses' => 0])]);
        $client = $this->clientTo($daemon);
        $client->databases->restore('main.sqlite', path: 'restored.sqlite', umask: 0);
        $client->session->status(after: '0', limit: 5);
        [$restore, $status] = $daemon->requests();
        self::assertSame(0, self::requestBody($restore)['umask']);
        self::assertSame($this->workingDirectory . '/restored.sqlite', self::requestBody($restore)['path']);
        self::assertSame(['after' => '0', 'limit' => '5'], $status['query']);
    }

    public function testPathsAcceptStringableAndCleanDotSegments(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], ['id' => 'r', 'path' => '/x'], 200, ['uses' => 0])]);
        $client = $this->clientTo($daemon);
        $client->backups->start(new class implements Stringable {
            public function __toString(): string
            {
                return 'data/../db/./main.sqlite';
            }
        });
        self::assertSame($this->workingDirectory . '/db/main.sqlite', self::requestBody($daemon->requests()[0])['path']);
    }

    public function testTimesAreSentInUtcWithTheirFraction(): void
    {
        $zoned = new DateTimeImmutable('2026-11-15T10:30:00.250000+01:00');
        self::assertSame('2026-11-15T09:30:00.25Z', Wire::wireTime($zoned));
        self::assertSame('2026-11-15T09:30:00Z', Wire::wireTime('2026-11-15T09:30:00Z'));
        $parsed = WireModel::parseTime('2026-11-15T09:30:00.123456789Z');
        self::assertNotNull($parsed);
        self::assertSame('2026-11-15T09:30:00.123456', $parsed->format('Y-m-d\TH:i:s.u'));
        self::assertSame('UTC', $parsed->getTimezone()->getName());
        $offset = WireModel::parseTime('2026-11-15T09:30:00-05:00');
        self::assertNotNull($offset);
        self::assertSame('-05:00', $offset->getTimezone()->getName());
        self::assertNull(WireModel::parseTime('2026-02-30T09:30:00Z'));
        self::assertEquals(new DateTimeImmutable('2026-11-15T14:30:00', new DateTimeZone('UTC')), $offset);
    }

    public function testUndecodableAnswerIsInvalidResponse(): void
    {
        $daemon = $this->startDaemon([
            ['status' => 200, 'body' => '{"name": 5}'],
            ['status' => 200, 'body' => 'not json'],
        ], ['ordered' => true]);
        $client = $this->clientTo($daemon);
        foreach ([1, 2] as $attempt) {
            try {
                $client->databases->get('main.sqlite');
                self::fail('the answer decoded');
            } catch (LitebackupError $error) {
                self::assertSame(ErrorCode::INVALID_RESPONSE, $error->errorCode, (string) $attempt);
            }
        }
    }

    public function testUnknownEnumValuesAndFieldsSurvive(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], array_merge(self::DATABASE, ['state' => 'hibernating', 'added_later' => 1]))]);
        $status = $this->clientTo($daemon)->databases->get('main.sqlite');
        self::assertSame('hibernating', $status->state);
        self::assertSame(0, $status->syncedServer->version);
    }

    public function testVersionsLimitZeroSendsNothing(): void
    {
        $daemon = $this->startDaemon([]);
        self::assertSame([], $this->clientTo($daemon)->databases->versions('main.sqlite', limit: 0));
        self::assertSame([], $daemon->requests());
    }

    public function testClosedClientRefusesCalls(): void
    {
        $daemon = $this->startDaemon([]);
        $client = $this->clientTo($daemon);
        $client->close();
        $client->close();
        try {
            $client->rules->list();
            self::fail('the call was sent');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::CLOSED, $error->errorCode);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testClientIsRefusedInAForkedChild(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], [], 200, ['uses' => 0])]);
        $client = $this->clientTo($daemon);
        $client->connect();
        $reportPath = $this->workingDirectory . '/child.txt';
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $client->rules->list();
                file_put_contents($reportPath, 'sent');
            } catch (LitebackupError $error) {
                file_put_contents($reportPath, $error->errorCode);
            }
            $client->close();
            // Leave without PHPUnit's shutdown, which belongs to the parent.
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);
        self::assertSame(ErrorCode::INVALID_ARGUMENT, file_get_contents($reportPath));
        // The parent's connection still works after the child closed its copy.
        self::assertSame([], $client->rules->list());
    }

    public function testExplicitEmptyCredentialIsNone(): void
    {
        $environmentDump = $this->workingDirectory . '/environment.txt';
        $binary = $this->script("env > '{$environmentDump}'\necho '{\"code\":\"launch_failed\",\"message\":\"no server\"}' >&2\nexit 1\n");
        $client = new Client(apiKey: '', session: 'jobs', binaryPath: $binary, environment: $this->environment([
            'LITEBACKUP_API_KEY' => 'litebackup_examplekeyvalue@backups.example.com',
        ]));
        try {
            $client->connect();
            self::fail('the launch succeeded');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::LAUNCH_FAILED, $error->errorCode);
            self::assertSame('starting Litebackup failed: no server', $error->getMessage());
        } finally {
            $client->close();
        }
        // The empty option replaces the variable, and proc_open leaves an
        // empty variable out, so the daemon gets none and uses the key it saved.
        self::assertStringNotContainsString('LITEBACKUP_API_KEY', (string) file_get_contents($environmentDump));
        $withKey = Launch::launchCommand('jobs', ['A' => 'b'], 'litebackup_k@h', $binary);
        self::assertSame(['A' => 'b', 'LITEBACKUP_PATH' => $binary, 'LITEBACKUP_API_KEY' => 'litebackup_k@h'], $withKey[1]);
    }

    public function testLaunchReceivesExactlyItsEnvironment(): void
    {
        $environmentDump = $this->workingDirectory . '/environment.txt';
        $binary = $this->script("env | sort > '{$environmentDump}'\nexit 3\n");
        $client = new Client(session: 'jobs', binaryPath: $binary, environment: ['HOME' => $this->workingDirectory, 'XDG_RUNTIME_DIR' => $this->workingDirectory . '/run']);
        try {
            $client->connect();
            self::fail('the launch succeeded');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::LAUNCH_FAILED, $error->errorCode);
            self::assertStringContainsString('exit status 3', $error->getMessage());
        } finally {
            $client->close();
        }
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($environmentDump)), static fn(string $line): bool => $line !== '' && !str_starts_with($line, 'PWD=') && !str_starts_with($line, 'SHLVL=') && !str_starts_with($line, '_=')));
        self::assertSame([
            'HOME=' . $this->workingDirectory,
            'LITEBACKUP_PATH=' . $binary,
            'XDG_RUNTIME_DIR=' . $this->workingDirectory . '/run',
        ], $lines);
    }

    public function testLaunchRefusalNamesDrainRequired(): void
    {
        $binary = $this->script("echo '{\"level\":\"warn\",\"event\":\"x\",\"code\":\"busy\",\"message\":\"a warning\"}' >&2\necho '{\"code\":\"drain_required\",\"message\":\"shut down first\"}' >&2\nexit 1\n");
        $client = new Client(session: 'jobs', binaryPath: $binary, environment: $this->environment());
        try {
            $client->connect();
            self::fail('the launch succeeded');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::DRAIN_REQUIRED, $error->errorCode);
            self::assertSame('starting Litebackup failed: shut down first', $error->getMessage());
        } finally {
            $client->close();
        }
    }

    public function testExplicitSocketNeverLaunches(): void
    {
        $client = new Client(socketPath: $this->workingDirectory . '/none.sock', binaryPath: '/nonexistent/litebackup');
        try {
            $client->connect();
            self::fail('the connect succeeded');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::CONNECTION_FAILED, $error->errorCode);
        } finally {
            $client->close();
        }
    }

    public function testForegroundBackupReportsItsFailures(): void
    {
        $arguments = $this->workingDirectory . '/arguments.txt';
        $binary = $this->script(
            "printf '%s\\n' \"\$@\" > '{$arguments}'\n"
            . "echo 'a log line' >&2\n"
            . "echo '{\"code\":\"busy\",\"message\":\"a.db is written by worker-2\"}' >&2\n"
            . "echo '{\"code\":\"busy\",\"message\":\"a.db is written by worker-2\"}' >&2\n"
            . "echo '{\"code\":\"busy\",\"message\":\"2 files are busy\"}' >&2\n"
            . "exit 1\n",
        );
        $client = new Client(session: 'jobs', binaryPath: $binary, environment: $this->environment());
        try {
            $client->backups->once('a.db', prefix: 'tenants', durability: 'cloud', timeout: 1.5);
            self::fail('the backup succeeded');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::BUSY, $error->errorCode);
            self::assertSame("2 files are busy\na.db is written by worker-2", $error->getMessage());
        } finally {
            $client->close();
        }
        self::assertSame(
            ['backup', '--stdout-format', 'json', '--durability', 'cloud', '--session', 'jobs', '--timeout', '1500ms', '--', $this->workingDirectory . '/a.db', 'tenants/'],
            explode("\n", trim((string) file_get_contents($arguments))),
        );
    }

    public function testForegroundBackupSucceedsAndTimesOut(): void
    {
        $succeeding = new Client(session: 'jobs', binaryPath: $this->script("exit 0\n"), environment: $this->environment());
        $succeeding->backups->once('a.db');
        $succeeding->close();
        $previousGrace = Foreground::$deadlineGraceSeconds;
        Foreground::$deadlineGraceSeconds = 0.2;
        $hanging = new Client(session: 'jobs', binaryPath: $this->script("exec sleep 30\n"), environment: $this->environment());
        $started = hrtime(true);
        try {
            $hanging->backups->once('a.db', timeout: 0.1);
            self::fail('the backup finished');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::TIMEOUT, $error->errorCode);
            self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
        } finally {
            Foreground::$deadlineGraceSeconds = $previousGrace;
            $hanging->close();
        }
    }

    public function testForegroundBackupNeedsASession(): void
    {
        $client = new Client(socketPath: '/nonexistent/client.sock');
        try {
            $client->backups->once('a.db');
            self::fail('the backup ran');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        } finally {
            $client->close();
        }
    }

    public function testSessionSocketsAndTheirChecks(): void
    {
        [$session, $socket] = Paths::sessionSocket(null, ['XDG_RUNTIME_DIR' => '/run/user/7', 'HOME' => $this->workingDirectory]);
        self::assertSame(['default', '/run/user/7/litebackup/default/client.sock'], [$session, $socket]);
        [, $fromVariable] = Paths::sessionSocket(null, ['LITEBACKUP_SESSION' => 'jobs', 'XDG_RUNTIME_DIR' => '/r', 'HOME' => $this->workingDirectory]);
        self::assertSame('/r/litebackup/jobs/client.sock', $fromVariable);
        [, $temporary] = Paths::sessionSocket('jobs', ['TMPDIR' => $this->workingDirectory, 'HOME' => $this->workingDirectory]);
        self::assertSame($this->workingDirectory . '/litebackup-' . Paths::userID() . '/litebackup/jobs/client.sock', $temporary);
        self::assertSame(0o700, fileperms($this->workingDirectory . '/litebackup-' . Paths::userID()) & 0o777);
        try {
            Paths::sessionSocket('Bad Name', ['XDG_RUNTIME_DIR' => '/r']);
            self::fail('the session name was accepted');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
        $shared = $this->workingDirectory . '/shared';
        mkdir($shared, 0o777);
        chmod($shared, 0o777);
        try {
            Paths::privateRuntimeDirectory($shared, Paths::userID());
            self::fail('a directory open to others was accepted');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
    }

    public function testUmaskIsReadFromTheStatusFile(): void
    {
        $status = $this->workingDirectory . '/status';
        file_put_contents($status, "Name:\tphp\nUmask:\t0027\nState:\tR\n");
        self::assertSame(0o027, Umask::statusUmask($status));
        file_put_contents($status, "Name:\tphp\n");
        self::assertNull(Umask::statusUmask($status));
        $previous = umask(0o077);
        try {
            self::assertSame(0o077, Umask::currentUmask());
            self::assertSame(0, Umask::requestUmask(0));
        } finally {
            umask($previous);
        }
    }

    public function testConnectProbeEndsWithTheCallDeadline(): void
    {
        $daemon = $this->startDaemon([], ['probe_delay_seconds' => 10]);
        $client = $this->clientTo($daemon);
        $started = hrtime(true);
        try {
            $client->call(Wire::database('main.sqlite')->withAnswerDeadline(0.3));
            self::fail('the call was answered');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::TIMEOUT, $error->errorCode);
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    public function testArgumentJsonCannotCarryFailsBeforeConnecting(): void
    {
        // The socket does not exist, so a connect would fail with connection_failed.
        $client = new Client(socketPath: $this->workingDirectory . '/none.sock');
        try {
            $client->backups->start("/tmp/\xFF.sqlite");
            self::fail('the call was sent');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        } finally {
            $client->close();
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testCloseFromASignalHandlerEndsAWaitingCall(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json(['path' => '/v1/backup-rules/list'], ['rules' => []], 200, ['delay_seconds' => 20])]);
        $client = $this->clientTo($daemon);
        $client->connect();
        $previous = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($client): void {
            $client->close();
        });
        pcntl_alarm(1);
        $started = hrtime(true);
        try {
            $client->rules->list();
            self::fail('the call was answered');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::CLOSED, $error->errorCode);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($previous);
        }
        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }


    /**
     * An environment for a launched binary, with a runtime directory of the test's.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function environment(array $extra = []): array
    {
        return $extra + [
            'PATH' => (string) getenv('PATH'),
            'HOME' => $this->workingDirectory,
            'XDG_RUNTIME_DIR' => $this->workingDirectory . '/run',
        ];
    }

    /** A shell script standing in for the litebackup binary. */
    private function script(string $body): string
    {
        $path = $this->workingDirectory . '/litebackup-' . bin2hex(random_bytes(3));
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);
        return $path;
    }
}
