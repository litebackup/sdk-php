<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use PHPUnit\Framework\Attributes\DataProvider;

/** Each operation sends the one request its fixture names, or fails locally without sending. */
final class RequestsContractTest extends DaemonTestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return ContractFixtures::provide('requests');
    }

    #[DataProvider('fixtures')]
    public function testRequest(string $name): void
    {
        $fixture = ContractFixtures::read('requests', $name, $this->workingDirectory);
        $daemon = $this->startDaemon([FakeDaemon::json([], $fixture['response'] ?? (object) [], 200, ['uses' => 0])]);
        $client = $this->clientTo($daemon);
        $client->connect();
        /** @var array<string, mixed> $args */
        $args = $fixture['args'];
        $operation = ContractFixtures::text($fixture, 'operation');
        if (isset($fixture['error'])) {
            /** @var array{code: string} $expected */
            $expected = $fixture['error'];
            try {
                ContractFixtures::invoke($client, $operation, $args);
                self::fail($operation . ' did not fail');
            } catch (LitebackupError $error) {
                self::assertSame($expected['code'], $error->errorCode, $error->getMessage());
            }
            self::assertSame([], $daemon->requests());
            return;
        }
        ContractFixtures::invoke($client, $operation, $args);
        $requests = $daemon->requests();
        self::assertCount(1, $requests);
        /** @var array{method: string, path: string, query: array<string, string>, body?: mixed} $expected */
        $expected = $fixture['request'];
        $sent = $requests[0];
        self::assertSame($expected['method'], $sent['method']);
        self::assertSame($expected['path'], $sent['path']);
        $expectedQuery = $expected['query'];
        ksort($expectedQuery);
        $sentQuery = $sent['query'];
        ksort($sentQuery);
        self::assertSame($expectedQuery, $sentQuery);
        $sentBody = $sent['body'] === '' ? null : json_decode($sent['body'], true);
        $expectedBody = $expected['body'] ?? null;
        if (is_array($expectedBody) && is_array($sentBody)) {
            ksort($expectedBody);
            ksort($sentBody);
        }
        self::assertSame($expectedBody, $sentBody);
    }
}
