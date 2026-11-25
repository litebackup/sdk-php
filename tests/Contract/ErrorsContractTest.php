<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** A daemon's non-200 answer becomes the error its fixture names. */
final class ErrorsContractTest extends DaemonTestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return ContractFixtures::provide('errors');
    }

    #[DataProvider('fixtures')]
    public function testError(string $name): void
    {
        $fixture = ContractFixtures::read('errors', $name);
        /** @var array{status: int, content_type: string, body: string} $response */
        $response = $fixture['response'];
        $daemon = $this->startDaemon([[
            'status' => $response['status'],
            'content_type' => $response['content_type'],
            'body' => $response['body'],
        ]]);
        $client = $this->clientTo($daemon);
        try {
            $client->rules->list();
            self::fail('the call did not fail');
        } catch (LitebackupError $error) {
            /** @var array{code: string, message: string, status: int, details: array<string, mixed>} $expected */
            $expected = $fixture['expect'];
            self::assertSame(
                [$expected['code'], $expected['message'], $expected['status'], $expected['details']],
                [$error->errorCode, $error->getMessage(), $error->status, $error->details],
            );
            self::assertSame($expected['code'] . ': ' . $expected['message'], $error->describe());
        }
    }
}
