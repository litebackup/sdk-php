<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\ErrorGroup;
use Litebackup\LeaseCleanupError;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use Litebackup\Tests\Support\StepAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * A pool lease's steps, the daemon calls it makes, and the errors the lease
 * and closing the pool throw. PHP has no async client, so the fixtures that
 * cancel are skipped, as they are for sync Python and Ruby.
 */
final class LifecycleContractTest extends DaemonTestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return ContractFixtures::provide('lifecycle');
    }

    #[DataProvider('fixtures')]
    public function testLifecycle(string $name): void
    {
        $fixture = ContractFixtures::read('lifecycle', $name, $this->workingDirectory);
        $skipReason = ContractFixtures::skipReason($fixture);
        if ($skipReason !== null) {
            self::markTestSkipped($skipReason);
        }
        if (isset($fixture['cancel'])) {
            self::markTestSkipped('PHP has no async client, so it has no cancellation; the async SDKs cover it');
        }
        /** @var list<string> $failing */
        $failing = $fixture['fail'];
        /** @var array{name: string, path?: string, durability?: string, offload?: bool, require_existing?: bool, cleanup_timeout?: string} $lease */
        $lease = $fixture['lease'];
        $daemon = $this->startDaemon($this->answers($fixture, $lease['name'], $failing));
        $client = $this->clientTo($daemon);
        $adapter = new StepAdapter($failing);
        $workError = null;
        $raised = null;
        $closeRaised = null;
        $pool = null;
        try {
            $pool = $client->databases->pool($adapter, cleanupTimeout: ContractFixtures::duration($lease['cleanup_timeout'] ?? null));
        } catch (Throwable $error) {
            $raised = $error;
        }
        if ($pool !== null) {
            try {
                $pool->lease(
                    $lease['name'],
                    function (object $handle) use ($adapter, $failing, &$workError): void {
                        $adapter->step('work');
                        if (in_array('work', $failing, true)) {
                            $workError = new RuntimeException('work failed');
                            throw $workError;
                        }
                    },
                    path: $lease['path'] ?? null,
                    durability: $lease['durability'] ?? null,
                    offload: $lease['offload'] ?? false,
                    requireExisting: $lease['require_existing'] ?? false,
                );
            } catch (Throwable $error) {
                $raised = $error;
            }
            try {
                $pool->close();
            } catch (Throwable $error) {
                $closeRaised = $error;
            }
        }
        /** @var array<string, mixed> $expect */
        $expect = $fixture['expect'];
        self::assertSame($expect['steps'], $this->steps($adapter, $daemon));
        if (isset($expect['open_path'])) {
            self::assertSame($expect['open_path'], $adapter->openPath);
        }
        if (isset($expect['created_file'])) {
            self::assertFileExists(ContractFixtures::text($expect, 'created_file'));
        }
        if (isset($expect['missing_file'])) {
            self::assertFileDoesNotExist(ContractFixtures::text($expect, 'missing_file'));
        }
        foreach ($daemon->requests() as $request) {
            $expectedBody = match ($request['path']) {
                '/v1/databases/acquire' => $expect['acquire_body'] ?? null,
                '/v1/databases/release' => $expect['release_body'] ?? null,
                default => null,
            };
            if (!is_array($expectedBody)) {
                continue;
            }
            self::assertEquals($expectedBody, self::withSpentCleanup(self::requestBody($request), $expectedBody), $request['path']);
        }
        /** @var list<array<string, mixed>> $expectedRaised */
        $expectedRaised = $expect['raised'];
        /** @var list<array<string, mixed>> $expectedCloseRaised */
        $expectedCloseRaised = $expect['close_raised'];
        $this->checkRaised('the lease', $raised, $expectedRaised, $adapter, $workError);
        $this->checkRaised('closing the pool', $closeRaised, $expectedCloseRaised, $adapter, $workError);
    }

    /**
     * The daemon's answers: each database step, failing when the fixture says so.
     *
     * @param array<string, mixed> $fixture
     * @param list<string> $failing
     * @return list<array<string, mixed>>
     */
    private function answers(array $fixture, string $name, array $failing): array
    {
        $bodies = [
            'acquire' => $fixture['acquire_response'],
            'sync' => [
                'name' => $name, 'path' => '/x', 'role' => 'origin', 'state' => 'synced', 'acquired' => true,
                'synced_server' => ['version' => 3], 'synced_cloud' => ['version' => 3],
            ],
            'release' => [
                'database' => ['name' => $name, 'path' => '/x', 'role' => 'origin', 'state' => 'synced'],
                'checkpointed' => false, 'vacuumed' => false, 'offloaded' => false,
            ],
        ];
        $answers = [];
        foreach ($bodies as $step => $body) {
            $match = ['path' => '/v1/databases/' . $step];
            $answers[] = in_array($step, $failing, true)
                ? FakeDaemon::json($match, ['code' => 'unavailable', 'message' => $step . ' failed'], 503, ['uses' => 0])
                : FakeDaemon::json($match, $body, 200, ['uses' => 0]);
        }
        return $answers;
    }

    /**
     * The adapter's steps and the daemon's database calls, in the order they happened.
     *
     * @return list<string>
     */
    private function steps(StepAdapter $adapter, FakeDaemon $daemon): array
    {
        $timeline = $adapter->steps;
        foreach ($daemon->requests() as $request) {
            if (str_starts_with($request['path'], '/v1/databases/')) {
                $timeline[] = [substr($request['path'], strlen('/v1/databases/')), $request['monotonic']];
            }
        }
        usort($timeline, static fn(array $left, array $right): int => $left[1] <=> $right[1]);
        return array_map(static fn(array $step): string => $step[0], $timeline);
    }

    /**
     * The release gets what closing left of the cleanup timeout, so a
     * timeout_millis up to a second short of the fixture's full timeout reads
     * as the fixture's value.
     *
     * @param array<string, mixed> $body
     * @param array<array-key, mixed> $expected
     * @return array<string, mixed>
     */
    private static function withSpentCleanup(array $body, array $expected): array
    {
        $sent = $body['timeout_millis'] ?? null;
        $wanted = $expected['timeout_millis'] ?? null;
        if (is_int($sent) && is_int($wanted) && $wanted - $sent >= 0 && $wanted - $sent <= 1000) {
            $body['timeout_millis'] = $wanted;
        }
        return $body;
    }

    /** @return list<Throwable> */
    private static function flatten(?Throwable $error): array
    {
        if ($error === null) {
            return [];
        }
        return $error instanceof ErrorGroup ? $error->errors : [$error];
    }

    /** @param list<array<string, mixed>> $expectedErrors */
    private function checkRaised(string $call, ?Throwable $raised, array $expectedErrors, StepAdapter $adapter, ?Throwable $workError): void
    {
        $errors = self::flatten($raised);
        self::assertCount(count($expectedErrors), $errors, $call . ' threw ' . ($raised?->getMessage() ?? 'nothing'));
        foreach ($expectedErrors as $index => $expected) {
            $actual = $errors[$index];
            $source = $expected['source'] ?? null;
            if ($source === 'work') {
                self::assertSame($workError, $actual, $call);
                continue;
            }
            if ($source === 'open') {
                self::assertSame($adapter->openError, $actual, $call);
                continue;
            }
            self::assertInstanceOf(LitebackupError::class, $actual, $actual->getMessage());
            self::assertSame($expected['code'], $actual->errorCode, $actual->getMessage());
            if (array_key_exists('details', $expected)) {
                self::assertEquals($expected['details'], $actual->details);
            }
            if (array_key_exists('step', $expected)) {
                self::assertInstanceOf(LeaseCleanupError::class, $actual);
                self::assertSame($expected['step'], $actual->step);
            }
            if (($expected['cause'] ?? null) === 'close') {
                self::assertSame($adapter->closeError, $actual->getPrevious());
            }
            if (($expected['cause'] ?? null) === 'release') {
                $cause = $actual->getPrevious();
                self::assertInstanceOf(LitebackupError::class, $cause);
                self::assertSame('unavailable', $cause->errorCode);
            }
        }
    }
}
