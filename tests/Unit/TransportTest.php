<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\ErrorCode;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use Litebackup\Transport;
use Litebackup\Wire;

/** HTTP framing, deadlines, connection reuse, and retries over the socket. */
final class TransportTest extends DaemonTestCase
{
    private const HEAD = "HTTP/1.1 200 OK\r\nLitebackup-Client-Version: 1.2.0\r\n";

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function raw(string $bytes, array $extra = []): array
    {
        return ['raw' => $bytes] + $extra;
    }

    public function testContentLengthChunkedAndStreamEndBodies(): void
    {
        $daemon = $this->startDaemon([
            self::raw(self::HEAD . "Content-Length: 5\r\n\r\nhello"),
            self::raw(self::HEAD . "Transfer-Encoding: chunked\r\n\r\n3;ext=1\r\nabc\r\n2\r\nde\r\n0\r\nTrailer: x\r\n\r\n"),
            self::raw(self::HEAD . "\r\nuntil the end", ['close' => true]),
        ], ['ordered' => true]);
        $transport = new Transport($daemon->socketPath);
        self::assertSame('hello', $transport->request('GET', '/v1/a', null, 5.0)->body);
        $chunked = $transport->request('GET', '/v1/b', null, 5.0);
        self::assertSame('abcde', $chunked->body);
        self::assertSame('1.2.0', $chunked->clientVersion);
        self::assertSame('until the end', $transport->request('GET', '/v1/c', null, 5.0)->body);
        self::assertCount(3, $daemon->requests());
    }

    public function testInformationalAnswerPrecedesTheRealOne(): void
    {
        $daemon = $this->startDaemon([
            self::raw("HTTP/1.1 100 Continue\r\n\r\n" . self::HEAD . "Content-Length: 2\r\n\r\nok"),
        ]);
        $transport = new Transport($daemon->socketPath);
        $answer = $transport->request('POST', '/v1/a', '{}', 5.0);
        self::assertSame(200, $answer->status);
        self::assertSame('ok', $answer->body);
    }

    public function testTruncatedChunkIsAConnectionFailure(): void
    {
        $daemon = $this->startDaemon([
            ['raw' => self::HEAD . "Transfer-Encoding: chunked\r\n\r\n10\r\nshort", 'delay_seconds' => 0],
            ['close' => true, 'uses' => 0],
        ]);
        $transport = new Transport($daemon->socketPath);
        $failure = $this->callFailure(fn() => $transport->request('POST', '/v1/a', '{}', 1.0));
        self::assertContains($failure->errorCode, [ErrorCode::TIMEOUT, ErrorCode::CONNECTION_FAILED]);
    }

    public function testBadChunkSizeAndBadHttp(): void
    {
        $daemon = $this->startDaemon([
            self::raw(self::HEAD . "Transfer-Encoding: chunked\r\n\r\nzz\r\n"),
            self::raw("SMTP ready\r\n\r\n"),
        ], ['ordered' => true]);
        $transport = new Transport($daemon->socketPath);
        self::assertSame(ErrorCode::CONNECTION_FAILED, $this->callFailure(fn() => $transport->request('POST', '/v1/a', '{}', 2.0))->errorCode);
        $notHttp = $this->callFailure(fn() => $transport->request('POST', '/v1/b', '{}', 2.0));
        self::assertSame(ErrorCode::CONNECTION_FAILED, $notHttp->errorCode);
        self::assertStringContainsString('not HTTP', $notHttp->getMessage());
    }

    public function testTrickledAnswerEndsWithTheDeadline(): void
    {
        $daemon = $this->startDaemon([
            ['trickle' => ['bytes' => "HTTP/1.1 200 OK\r\nX-Slow: ", 'seconds' => 0.02]],
        ]);
        $transport = new Transport($daemon->socketPath);
        $started = hrtime(true);
        $failure = $this->callFailure(fn() => $transport->request('GET', '/v1/a', null, 0.5));
        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertSame(ErrorCode::TIMEOUT, $failure->errorCode);
        self::assertStringContainsString('may still finish', $failure->getMessage());
        self::assertLessThan(2.0, $elapsed);
    }

    public function testLargeRequestBodyIsWrittenWhole(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], ['ok' => true], 200, ['uses' => 0])]);
        $transport = new Transport($daemon->socketPath);
        $body = json_encode(['name' => str_repeat('x', 4 << 20)], JSON_THROW_ON_ERROR);
        self::assertSame(200, $transport->request('POST', '/v1/a', $body, 10.0)->status);
        self::assertSame(strlen($body), strlen($daemon->requests()[0]['body']));
    }

    public function testConnectionIsReusedUntilTheAnswerSaysClose(): void
    {
        $daemon = $this->startDaemon([
            self::raw(self::HEAD . "Content-Length: 1\r\n\r\na"),
            self::raw(self::HEAD . "Connection: close\r\nContent-Length: 1\r\n\r\nb"),
            self::raw(self::HEAD . "Content-Length: 1\r\n\r\nc"),
        ], ['ordered' => true]);
        $transport = new Transport($daemon->socketPath);
        foreach (['a', 'b', 'c'] as $expected) {
            self::assertSame($expected, $transport->request('GET', '/v1/x', null, 5.0)->body);
        }
        self::assertCount(3, $daemon->requests());
    }

    public function testGetOnAReusedConnectionTheDaemonClosedIsSentAgain(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/first'], ['n' => 1]),
            ['match' => ['path' => '/v1/second'], 'close' => true],
            FakeDaemon::json(['path' => '/v1/second'], ['n' => 2]),
        ]);
        $transport = new Transport($daemon->socketPath);
        self::assertSame('{"n":1}', $transport->request('GET', '/v1/first', null, 5.0)->body);
        self::assertSame('{"n":2}', $transport->request('GET', '/v1/second', null, 5.0)->body);
        self::assertSame(['/v1/first', '/v1/second', '/v1/second'], array_column($daemon->requests(), 'path'));
    }

    public function testPostIsNeverSentTwice(): void
    {
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/first'], ['n' => 1]),
            ['match' => ['path' => '/v1/second'], 'close' => true],
            FakeDaemon::json(['path' => '/v1/second'], ['n' => 2]),
        ]);
        $transport = new Transport($daemon->socketPath);
        $transport->request('GET', '/v1/first', null, 5.0);
        $failure = $this->callFailure(fn() => $transport->request('POST', '/v1/second', '{}', 5.0));
        self::assertSame(ErrorCode::CONNECTION_FAILED, $failure->errorCode);
        self::assertSame(['/v1/first', '/v1/second'], array_column($daemon->requests(), 'path'));
    }

    public function testIdleConnectionTheDaemonDroppedIsNotReused(): void
    {
        $daemon = $this->startDaemon([FakeDaemon::json([], ['n' => 1], 200, ['uses' => 0])]);
        $transport = new Transport($daemon->socketPath);
        $transport->request('POST', '/v1/a', '{}', 5.0);
        $daemon->stop();
        $daemon->serve();
        // The idle connection reads as closed, so the POST goes on a new one.
        self::assertSame(200, $transport->request('POST', '/v1/b', '{}', 5.0)->status);
    }

    public function testAnswerOverTheLimitIsInvalid(): void
    {
        $daemon = $this->startDaemon([self::raw(self::HEAD . 'Content-Length: ' . (Wire::RESPONSE_LIMIT + 1) . "\r\n\r\n")]);
        $transport = new Transport($daemon->socketPath);
        self::assertSame(ErrorCode::INVALID_RESPONSE, $this->callFailure(fn() => $transport->request('GET', '/v1/a', null, 5.0))->errorCode);
    }

    public function testClosedTransportRefusesCalls(): void
    {
        $daemon = $this->startDaemon([]);
        $transport = new Transport($daemon->socketPath);
        $transport->close();
        self::assertSame(ErrorCode::CLOSED, $this->callFailure(fn() => $transport->request('GET', '/v1/a', null, 5.0))->errorCode);
    }

    public function testMissingSocketIsAConnectionFailure(): void
    {
        $transport = new Transport($this->workingDirectory . '/missing.sock');
        self::assertSame(ErrorCode::CONNECTION_FAILED, $this->callFailure(fn() => $transport->request('GET', '/v1/a', null, 5.0))->errorCode);
    }

    /** @param callable(): mixed $call */
    private function callFailure(callable $call): LitebackupError
    {
        try {
            $call();
        } catch (LitebackupError $error) {
            return $error;
        }
        self::fail('the call did not fail');
    }
}
