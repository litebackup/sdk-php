<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Version;
use PHPUnit\Framework\Attributes\DataProvider;

/** A connect accepts a daemon at the minimum version or later, and refuses an older one. */
final class VersionContractTest extends DaemonTestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return ContractFixtures::provide('version');
    }

    #[DataProvider('fixtures')]
    public function testVersion(string $name): void
    {
        $fixture = ContractFixtures::read('version', $name);
        $daemon = $this->startDaemon([], ['client_version' => $fixture['client_version'] ?? null]);
        $client = $this->clientTo($daemon);
        if ($fixture['accepted'] === true) {
            $client->connect();
            self::assertSame($fixture['client_version'], $client->clientVersion());
            return;
        }
        try {
            $client->connect();
            self::fail('the connect did not fail');
        } catch (LitebackupError $error) {
            /** @var array{code: string} $expected */
            $expected = $fixture['expect'];
            self::assertSame($expected['code'], $error->errorCode);
            self::assertStringContainsString(Version::MINIMUM_DAEMON_VERSION, $error->getMessage());
            self::assertStringContainsString('litebackup drain', $error->getMessage());
        }
    }
}
