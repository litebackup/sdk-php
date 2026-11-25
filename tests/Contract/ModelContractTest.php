<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use DateTimeImmutable;
use Litebackup\ErrorCode;
use Litebackup\Generated\AcquireResult;
use Litebackup\Generated\DatabaseStatus;
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
        self::assertSame(2, $shutdown->released);
        $failed = array_map(static fn($failure): array => [$failure->name, $failure->code, $failure->message], $shutdown->failed);
        self::assertSame([['orders.sqlite', ErrorCode::LOCK_UNAVAILABLE, 'another machine writes the database (worker-3)']], $failed);
        self::assertSame([], ShutdownResult::fromWire(ContractFixtures::read('requests', 'shutdown-default')['response'])->failed);
    }
}
