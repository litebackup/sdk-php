<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use DateTimeInterface;
use DateTimeZone;
use Generator;
use Litebackup\Client;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Iterators send each page's request in order and yield the items their fixtures name. */
final class PaginationContractTest extends DaemonTestCase
{
    /** @return array<string, array{string, string}> */
    public static function fixtures(): array
    {
        $paginationCases = [];
        foreach (['pagination', 'events'] as $fixtureDirectory) {
            foreach (ContractFixtures::names($fixtureDirectory) as $name) {
                $paginationCases[$fixtureDirectory . '/' . $name] = [$fixtureDirectory, $name];
            }
        }
        return $paginationCases;
    }

    #[DataProvider('fixtures')]
    public function testIteration(string $fixtureDirectory, string $name): void
    {
        $fixture = ContractFixtures::read($fixtureDirectory, $name);
        $skipReason = ContractFixtures::skipReason($fixture);
        if ($skipReason !== null) {
            self::markTestSkipped($skipReason);
        }
        /** @var list<array{request: array<string, mixed>, response: array{status: int, body: mixed}}> $exchanges */
        $exchanges = $fixture['exchanges'];
        $answers = array_map(static fn(array $exchange): array => [
            'match' => $exchange['request'],
            'status' => $exchange['response']['status'],
            'json' => $exchange['response']['body'],
        ], $exchanges);
        $daemon = $this->startDaemon($answers, ['ordered' => true]);
        $client = $this->clientTo($daemon);
        $itemLimit = is_int($fixture['item_limit'] ?? null) ? $fixture['item_limit'] : null;
        $key = ContractFixtures::camelCase(ContractFixtures::text($fixture, 'item_key'));
        $items = [];
        $ended = null;
        try {
            foreach ($this->iteration($client, $fixture) as $item) {
                $items[] = self::fixtureValue($item->{$key});
                if ($itemLimit !== null && count($items) >= $itemLimit) {
                    break;
                }
            }
        } catch (LitebackupError $error) {
            $ended = $error;
        }
        /** @var array{items: list<mixed>, error?: array{code: string}} $expected */
        $expected = $fixture['expect'];
        self::assertSame($expected['items'], $items);
        if (isset($expected['error'])) {
            self::assertNotNull($ended);
            self::assertSame($expected['error']['code'], $ended->errorCode);
        } elseif ($ended !== null) {
            throw $ended;
        }
        self::assertCount(count($exchanges), $daemon->requests());
    }

    /**
     * @param array<string, mixed> $fixture
     * @return Generator<int, object, mixed, void>
     */
    private function iteration(Client $client, array $fixture): Generator
    {
        /** @var array{name: string, after?: int, limit?: int, wait?: string} $args */
        $args = $fixture['args'];
        return match ($fixture['operation']) {
            'databases.all' => $client->databases->all(),
            'databases.versions' => $client->databases->allVersions($args['name'], $args['after'] ?? 0, $args['limit'] ?? null),
            'events.watch' => $client->events->watch($args['after'] ?? 0, ContractFixtures::duration($args['wait'] ?? null)),
            default => throw new RuntimeException('no PHP mapping for ' . json_encode($fixture['operation'])),
        };
    }

    /** An iterated field as a fixture writes it: times as RFC 3339 in UTC. */
    private static function fixtureValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        return $value;
    }
}
