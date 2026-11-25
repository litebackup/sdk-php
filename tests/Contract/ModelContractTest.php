<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use DateTimeImmutable;
use Litebackup\ErrorCode;
use Litebackup\Generated\AcquireResult;
use Litebackup\Generated\DatabaseStatus;
use Litebackup\Generated\ReleaseResult;
use Litebackup\Generated\ShutdownResult;
use Litebackup\Tests\Support\ContractFixtures;
use PHPUnit\Framework\TestCase;

/** The fixtures' answers decode into the generated models. */
final class ModelContractTest extends TestCase
{
    /** The databases-get answer carries the server time and the archive time. */
    public function testStatusTimes(): void
    {
        $status = DatabaseStatus::fromWire(ContractFixtures::read('requests', 'databases-get')['response']);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:31:00Z'), $status->syncedServer->time);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:30:00Z'), $status->syncedCloud->archived);
    }

    /** Each state's timestamp decodes: an offloaded database's, a retrying origin's, and a pending origin's. */
    public function testStateTimes(): void
    {
        $offloaded = DatabaseStatus::fromWire(ContractFixtures::read('requests', 'databases-offload')['response']);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:32:00Z'), $offloaded->offloaded);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:31:00Z'), $offloaded->synced);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:31:30Z'), $offloaded->released);
        $retrying = DatabaseStatus::fromWire(ContractFixtures::read('requests', 'databases-sync')['response']);
        self::assertSame('retrying', $retrying->state);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:25:00Z'), $retrying->retrying);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:29:00Z'), $retrying->failed);
        self::assertSame('unavailable: the server did not answer', $retrying->error);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:20:00Z'), $retrying->syncing);
        $pending = DatabaseStatus::fromWire(ContractFixtures::read('requests', 'databases-get-pending')['response']);
        self::assertSame('pending', $pending->state);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:33:00Z'), $pending->pending);
        self::assertNull($pending->syncing);
    }

    /** A release that offloaded says when it removed the file, and one that kept it has no offloaded. */
    public function testReleaseOffloaded(): void
    {
        $offloaded = ReleaseResult::fromWire(ContractFixtures::read('requests', 'release-all')['response']);
        self::assertEquals(new DateTimeImmutable('2026-11-15T09:32:00Z'), $offloaded->offloaded);
        self::assertNull(ReleaseResult::fromWire(ContractFixtures::read('requests', 'release-no-offload')['response'])->offloaded);
    }

    /** An acquired database's answer carries offloadDurability, and one not acquired has none. */
    public function testStatusOffloadDurability(): void
    {
        $acquired = AcquireResult::fromWire(ContractFixtures::read('requests', 'acquire-all')['response']);
        self::assertSame('cloud', $acquired->database->offloadDurability);
        $status = DatabaseStatus::fromWire(ContractFixtures::read('requests', 'databases-get')['response']);
        self::assertNull($status->offloadDurability);
    }

    /** The shutdown-all answer carries a database the drain did not drain, with its code and message. */
    public function testShutdownFailed(): void
    {
        $shutdown = ShutdownResult::fromWire(ContractFixtures::read('requests', 'shutdown-all')['response']);
        self::assertSame(2, $shutdown->releasedCount);
        $failed = array_map(static fn($failure): array => [$failure->name, $failure->code, $failure->message], $shutdown->failures);
        self::assertSame([['orders.sqlite', ErrorCode::LOCK_UNAVAILABLE, 'another machine writes the database (worker-3)']], $failed);
        self::assertSame([], ShutdownResult::fromWire(ContractFixtures::read('requests', 'shutdown-default')['response'])->failures);
    }
}
