<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\Client;
use Litebackup\ErrorCode;
use Litebackup\Failover;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * A failover reports roles only inside follow(), and only as long as the
 * daemon confirmed them within the confirmation limit.
 */
final class FailoverTest extends DaemonTestCase
{
    private const NAME = 'main.sqlite';

    private float $previousLimit = 15.0;
    private float $previousWait = 10.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLimit = Failover::$confirmationLimitSeconds;
        $this->previousWait = Failover::$eventWaitSeconds;
        Failover::$confirmationLimitSeconds = 1.0;
        Failover::$eventWaitSeconds = 0.5;
    }

    protected function tearDown(): void
    {
        Failover::$confirmationLimitSeconds = $this->previousLimit;
        Failover::$eventWaitSeconds = $this->previousWait;
        parent::tearDown();
    }

    /**
     * The answers a start needs: the log's end, read before and during
     * role reads, and the start itself.
     *
     * @return list<array<string, mixed>>
     */
    private static function startAnswers(string $role, int $logEnd = 5): array
    {
        return [
            FakeDaemon::json(['path' => '/v1/events/list', 'query' => ['after' => '-1']], ['events' => [], 'next' => $logEnd], 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/failovers/start'], ['name' => self::NAME, 'path' => '/var/lib/app/main.sqlite', 'role' => $role]),
        ];
    }

    /** @return array<string, mixed> */
    private static function event(int $id, string $type, string $message = '', ?string $role = null): array
    {
        $event = ['id' => $id, 'time' => '2026-11-15T09:30:00Z', 'name' => self::NAME, 'type' => $type, 'message' => $message];
        return $role === null ? $event : $event + ['role' => $role];
    }

    /**
     * A long-poll answer: events and the next cursor.
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function longPoll(array $events, int $next, array $extra = []): array
    {
        return FakeDaemon::json(['path' => '/v1/events/list'], ['events' => $events, 'next' => $next], 200, $extra);
    }

    /**
     * A long-poll that keeps answering with no events, as an idle daemon does after its wait.
     *
     * @return array<string, mixed>
     */
    private static function quietLongPolls(int $next): array
    {
        return self::longPoll([], $next, ['uses' => 0, 'delay_seconds' => 0.1]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function roleAnswer(string $role, array $extra = []): array
    {
        return FakeDaemon::json(['path' => '/v1/databases/status'], array_merge(['name' => self::NAME, 'path' => '/x', 'role' => $role, 'state' => 'synced', 'failover' => true], $extra), 200, ['uses' => 0]);
    }

    /**
     * Starts the failover, saving each role and refusal its callbacks hear.
     *
     * @param list<string> $heard
     */
    private function startFailover(Client $client, array &$heard): Failover
    {
        return $client->failovers->start(
            '/var/lib/app/main.sqlite',
            onRoleChange: static function (string $role) use (&$heard): void {
                $heard[] = $role;
            },
            onRefused: static function (string $message) use (&$heard): void {
                $heard[] = 'refused: ' . $message;
            },
            onError: static function (LitebackupError $error) use (&$heard): void {
                $heard[] = 'error: ' . $error->errorCode;
            },
        );
    }

    public function testFollowReportsTheStartRoleThenEachChange(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('replica'),
            self::longPoll([self::event(6, 'role_changed', 'origin', 'origin')], 6),
            self::quietLongPolls(6),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        self::assertSame([], $heard);
        self::assertSame(Failover::REPLICA, $failover->role());
        $failover->follow(1.2);
        self::assertSame(['replica', 'origin'], $heard);
        self::assertTrue($failover->isOrigin());
        $paths = array_column($daemon->requests(), 'path');
        self::assertSame(['/v1/events/list', '/v1/failovers/start', '/v1/events/list'], array_slice($paths, 0, 3));
        self::assertSame(['after' => '5', 'wait_millis' => '500', 'limit' => '1000'], $daemon->requests()[2]['query']);
    }

    /** A follow() of one second, as an app calls between its own work, still long-polls. */
    public function testShortFollowStillLongPolls(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('replica'),
            self::longPoll([self::event(6, 'role_changed', 'origin', 'origin')], 6),
            self::quietLongPolls(6),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $failover->follow(1.0);
        self::assertSame(['replica', 'origin'], $heard);
    }

    public function testRoleIsUnknownOnceUnconfirmedOutsideFollow(): void
    {
        Failover::$confirmationLimitSeconds = 0.3;
        $daemon = $this->startDaemon(self::startAnswers('origin'));
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        self::assertTrue($failover->isOrigin());
        usleep(400_000);
        self::assertSame(Failover::UNKNOWN, $failover->role());
        self::assertFalse($failover->isOrigin());
        self::assertSame([], $heard);
    }

    public function testFollowAfterAGapReadsTheRoleBeforeTrustingOrigin(): void
    {
        Failover::$confirmationLimitSeconds = 0.3;
        $daemon = $this->startDaemon([
            ...self::startAnswers('origin'),
            self::roleAnswer('origin'),
            self::quietLongPolls(5),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        usleep(400_000);
        $failover->follow(1.2);
        // The start role expired, so the first callback hears unknown, never a stale origin.
        self::assertSame(['unknown', 'origin'], $heard);
        $requests = array_map(
            static fn(array $request): string => $request['path'] . '?' . http_build_query($request['query']),
            $daemon->requests(),
        );
        self::assertSame(
            ['/v1/events/list?after=-1', '/v1/databases/status?name=main.sqlite', '/v1/events/list?after=-1'],
            array_slice($requests, 2, 3),
        );
    }

    public function testRefusedEventMakesTheRoleUnknown(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('origin'),
            self::longPoll([self::event(6, 'refused', 'the file holds writes the server lacks')], 6),
            self::roleAnswer('origin', ['state' => 'conflicted']),
            self::quietLongPolls(6),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $failover->follow(1.2);
        self::assertSame(['origin', 'unknown', 'refused: the file holds writes the server lacks'], $heard);
        self::assertSame(Failover::UNKNOWN, $failover->role());
    }

    public function testStopFromACallbackEndsFollow(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('replica'),
            self::longPoll([self::event(6, 'role_changed', 'origin', 'origin')], 6),
            FakeDaemon::json(['path' => '/v1/backup-rules/stop'], ['stopped' => 1]),
            self::quietLongPolls(6),
        ]);
        $failover = null;
        $heard = [];
        $failover = $this->clientTo($daemon)->failovers->start(
            '/var/lib/app/main.sqlite',
            onRoleChange: static function (string $role) use (&$heard, &$failover): void {
                $heard[] = $role;
                if ($role === Failover::ORIGIN) {
                    $failover?->stop();
                }
            },
        );
        $started = hrtime(true);
        $failover->follow(5.0);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertSame(['replica', 'origin'], $heard);
        self::assertTrue($failover->stopped());
        self::assertSame(Failover::UNKNOWN, $failover->role());
        self::assertContains('/v1/backup-rules/stop', array_column($daemon->requests(), 'path'));
    }

    public function testDaemonThatStopsAnsweringMakesTheRoleUnknownImmediately(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('origin'),
            self::longPoll([], 5, ['stop' => true, 'serve_after_seconds' => 0.4]),
            self::roleAnswer('origin'),
            self::quietLongPolls(5),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $failover->follow(2.0);
        self::assertSame(['origin', 'unknown', 'error: ' . ErrorCode::CONNECTION_FAILED], array_slice($heard, 0, 3));
        self::assertSame('origin', $heard[count($heard) - 1]);
        self::assertTrue($failover->isOrigin());
    }

    public function testExpiredCursorResumesFromTheLogEnd(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('replica', 5),
            FakeDaemon::json(['path' => '/v1/events/list', 'query' => ['after' => '5', 'wait_millis' => '500', 'limit' => '1000']], ['code' => 'cursor_expired', 'message' => 'the cursor expired'], 410),
            self::roleAnswer('origin'),
            self::quietLongPolls(5),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $failover->follow(1.2);
        self::assertSame(['replica', 'error: cursor_expired', 'origin'], $heard);
    }

    public function testSdkUnsupportedEndsFollowing(): void
    {
        $daemon = $this->startDaemon([
            ...self::startAnswers('origin'),
            FakeDaemon::json(['path' => '/v1/events/list'], ['code' => 'sdk_unsupported', 'message' => 'upgrade'], 426),
        ]);
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $started = hrtime(true);
        $failover->follow(5.0);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertSame(['origin', 'unknown', 'error: sdk_unsupported'], $heard);
        // Following ended for good: a later follow() sends nothing.
        self::assertTrue($failover->stopped());
        $sent = count($daemon->requests());
        $failover->follow(1.0);
        self::assertCount($sent, $daemon->requests());
        self::assertSame(['origin', 'unknown', 'error: sdk_unsupported'], $heard);
    }

    public function testClosingTheClientMakesTheRoleUnknownWithoutACallback(): void
    {
        $daemon = $this->startDaemon(self::startAnswers('origin'));
        $client = $this->clientTo($daemon);
        $heard = [];
        $failover = $this->startFailover($client, $heard);
        $client->close();
        self::assertSame(Failover::UNKNOWN, $failover->role());
        $failover->follow(0.5);
        self::assertSame([], $heard);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testRoleIsUnknownInAForkedChild(): void
    {
        $daemon = $this->startDaemon(self::startAnswers('origin'));
        $heard = [];
        $failover = $this->startFailover($this->clientTo($daemon), $heard);
        $reportPath = $this->workingDirectory . '/child.txt';
        $pid = pcntl_fork();
        if ($pid === 0) {
            file_put_contents($reportPath, $failover->role());
            posix_kill(posix_getpid(), SIGKILL);
        }
        pcntl_waitpid($pid, $status);
        self::assertSame(Failover::UNKNOWN, file_get_contents($reportPath));
        self::assertSame(Failover::ORIGIN, $failover->role());
    }
}
