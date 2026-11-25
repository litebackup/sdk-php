<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;

/** The fake daemon answers from its plan, gates answers, and stops and serves on command. */
final class FakeDaemonTest extends DaemonTestCase
{
    protected bool $sdkRequestsOnly = false;

    /**
     * Sends one raw HTTP request and reads the answer until the connection closes or timeout seconds pass.
     */
    private static function exchange(string $socketPath, string $request, float $timeout = 2.0): string
    {
        $socket = stream_socket_client('unix://' . $socketPath, $errorNumber, $errorText, 1.0);
        self::assertNotFalse($socket, (string) $errorText);
        fwrite($socket, $request);
        stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1_000_000));
        $answer = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $answer .= $chunk;
            if (str_contains($answer, "\r\n\r\n") && preg_match('/Content-Length: (\d+)/', $answer, $match) === 1) {
                [$head, $body] = explode("\r\n\r\n", $answer, 2);
                if (strlen($body) >= (int) $match[1]) {
                    break;
                }
            }
        }
        fclose($socket);
        return $answer;
    }

    public function testAnswersMatchedRequestsAndLogsThem(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/a'], ['a' => 1]),
            FakeDaemon::json(['path' => '/v1/b'], ['b' => 2]),
        ]);
        $answer = self::exchange($daemon->socketPath, "GET /v1/b?x=1 HTTP/1.1\r\nHost: litebackup\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 200', $answer);
        self::assertStringEndsWith('{"b":2}', $answer);
        $answer = self::exchange($daemon->socketPath, "GET /v1/c HTTP/1.1\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 500', $answer);
        $requests = $daemon->requests();
        self::assertSame(['/v1/b', '/v1/c'], array_column($requests, 'path'));
        self::assertSame(['x' => '1'], $requests[0]['query']);
    }

    public function testOrderedAnswersDenyALaterRequestFirst(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/a'], ['a' => 1]),
            FakeDaemon::json(['path' => '/v1/b'], ['b' => 2]),
        ], ['ordered' => true]);
        self::assertStringStartsWith('HTTP/1.1 500', self::exchange($daemon->socketPath, "GET /v1/b HTTP/1.1\r\n\r\n"));
        self::assertStringEndsWith('{"a":1}', self::exchange($daemon->socketPath, "GET /v1/a HTTP/1.1\r\n\r\n"));
    }

    public function testGatedAnswerWaitsUntilItsTime(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/a'], ['a' => 1], 200, ['after_seconds' => 0.3]),
        ]);
        $started = hrtime(true);
        self::assertStringEndsWith('{"a":1}', self::exchange($daemon->socketPath, "GET /v1/a HTTP/1.1\r\n\r\n"));
        self::assertGreaterThanOrEqual(0.25, (hrtime(true) - $started) / 1e9);
    }

    public function testStopsAndServesOnCommand(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], ['a' => 1], 200, ['uses' => 0])]);
        $daemon->stop();
        self::assertFalse(@stream_socket_client('unix://' . $daemon->socketPath, $errorNumber, $errorText, 0.5));
        $daemon->serve();
        self::assertStringEndsWith('{"a":1}', self::exchange($daemon->socketPath, "GET /v1/a HTTP/1.1\r\n\r\n"));
        $daemon->addAnswers([FakeDaemon::json(['path' => '/v1/z'], ['z' => 1])]);
    }

    public function testStopAnswerServesAgainOnTheSamePath(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/a'], ['a' => 1], 200, ['stop' => true, 'serve_after_seconds' => 0.3]),
            FakeDaemon::json(['path' => '/v1/b'], ['b' => 2]),
        ]);
        self::assertStringEndsWith('{"a":1}', self::exchange($daemon->socketPath, "GET /v1/a HTTP/1.1\r\n\r\n"));
        usleep(100_000);
        self::assertFalse(@stream_socket_client('unix://' . $daemon->socketPath, $errorNumber, $errorText, 0.5));
        usleep(400_000);
        self::assertStringEndsWith('{"b":2}', self::exchange($daemon->socketPath, "GET /v1/b HTTP/1.1\r\n\r\n"));
    }
}
