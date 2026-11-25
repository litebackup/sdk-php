<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * HTTP/1.1 over the daemon's unix socket, with a small keep-alive pool.
 *
 * PHP's own stream timeouts time each read on its own, so a daemon that
 * trickles an answer could keep a call past its deadline; this reads with
 * one monotonic deadline for the whole call, headers and every chunk, and
 * none when the call has no timeout. Streams are nonblocking and every wait
 * is a stream_select with what is left of the deadline. A GET that fails on
 * a reused connection before any answer arrives is sent once more, on a new
 * connection; nothing else is ever sent twice, and there is no proxy
 * lookup. Persistent streams are never used.
 *
 * @internal
 */
final class Transport
{
    public const POOL_SIZE = 4;
    private const READ_SIZE = 65536;
    /** Limits the status line and headers of one answer. */
    private const HEADER_LIMIT = 64 << 10;
    /** How long a connect may wait when the call has no deadline. */
    private const CONNECT_SECONDS = 60.0;

    /** @var list<TransportConnection> */
    private array $idle = [];
    private bool $closed = false;
    private int $ownerPID;

    public function __construct(public readonly string $socketPath)
    {
        $this->ownerPID = getmypid() ?: 0;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    /**
     * Sends one request and reads its whole answer. deadlineSeconds bounds
     * the whole call; null waits as long as the daemon takes.
     */
    public function request(string $method, string $target, ?string $body, ?float $deadlineSeconds): TransportAnswer
    {
        $deadline = $deadlineSeconds === null ? null : Clock::now() + $deadlineSeconds;
        $retried = false;
        while (true) {
            [$connection, $reused] = $this->lendConnection($retried);
            $reusable = false;
            try {
                [$answer, $reusable] = $this->exchange($connection, $reused, $method, $target, $body, $deadline);
                return $answer;
            } catch (TransportDeadlinePassed) {
                if ($this->closed) {
                    throw $this->closedError();
                }
                throw new LitebackupError(
                    ErrorCode::TIMEOUT,
                    "the daemon did not answer within the call's timeout; it may still finish the operation",
                );
            } catch (TransportConnectionBroken $broken) {
                if ($this->closed) {
                    throw $this->closedError();
                }
                if ($reused && !$retried && $method === 'GET' && !$connection->answerStarted) {
                    $retried = true;
                    continue;
                }
                throw new LitebackupError(ErrorCode::CONNECTION_FAILED, $broken->getMessage(), 0, [], $broken);
            } finally {
                // Every way out gives the connection back: to the idle pool
                // only after a whole, framed answer.
                $this->returnConnection($connection, $reusable);
            }
        }
    }

    /**
     * Closes the idle connections; later calls fail with closed. In a forked
     * child the descriptors are closed without a socket shutdown, which
     * would end the parent's calls.
     */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->idle as $connection) {
            $connection->close();
        }
        $this->idle = [];
    }

    private function closedError(): LitebackupError
    {
        return new LitebackupError(ErrorCode::CLOSED, 'the client is closed');
    }

    /**
     * A pooled idle connection, or a new one; with fresh, always a new one.
     * A forked child drops the parent's idle connections.
     *
     * @return array{TransportConnection, bool}
     */
    private function lendConnection(bool $fresh): array
    {
        if ($this->closed) {
            throw $this->closedError();
        }
        $pid = getmypid() ?: 0;
        if ($pid !== $this->ownerPID) {
            foreach ($this->idle as $connection) {
                $connection->close();
            }
            $this->idle = [];
            $this->ownerPID = $pid;
        }
        while (!$fresh && $this->idle !== []) {
            $connection = array_pop($this->idle);
            if ($connection->dropped()) {
                $connection->close();
                continue;
            }
            $connection->answerStarted = false;
            return [$connection, true];
        }
        return [new TransportConnection(), false];
    }

    private function returnConnection(TransportConnection $connection, bool $reusable): void
    {
        if (
            $reusable && !$this->closed && count($this->idle) < self::POOL_SIZE
            && $this->ownerPID === getmypid() && $connection->stream !== null
        ) {
            $this->idle[] = $connection;
            return;
        }
        $connection->close();
    }

    /**
     * What is left of the deadline. Checked before every read, write, and
     * wait, so a close from a signal handler ends a call waiting on the
     * daemon once the signal interrupts its wait.
     */
    private function remaining(?float $deadline): ?float
    {
        if ($this->closed) {
            throw $this->closedError();
        }
        if ($deadline === null) {
            return null;
        }
        $left = $deadline - Clock::now();
        if ($left <= 0) {
            throw new TransportDeadlinePassed("the call's deadline passed");
        }
        return $left;
    }

    /** @param resource $stream */
    private function wait($stream, bool $reading, ?float $deadline): void
    {
        $left = $this->remaining($deadline);
        $readable = $reading ? [$stream] : null;
        $writable = $reading ? null : [$stream];
        $failed = null;
        $whole = $left === null ? null : (int) floor($left);
        $micro = $left === null ? null : (int) (($left - floor($left)) * 1_000_000);
        $ready = @stream_select($readable, $writable, $failed, $whole, $micro);
        if ($ready === false) {
            // A signal interrupted the wait: look at the deadline and wait again.
            $this->remaining($deadline);
            return;
        }
        if ($ready === 0) {
            throw new TransportDeadlinePassed("the call's deadline passed");
        }
    }

    private function connect(TransportConnection $connection, ?float $deadline): void
    {
        $left = $this->remaining($deadline) ?? self::CONNECT_SECONDS;
        $stream = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errorNumber,
            $errorText,
            min($left, self::CONNECT_SECONDS),
        );
        if ($stream === false) {
            $reason = $errorText !== '' ? $errorText : (error_get_last()['message'] ?? 'connect failed');
            throw new TransportConnectionBroken('connecting to ' . $this->socketPath . ' failed: ' . $reason);
        }
        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);
        $connection->stream = $stream;
        if ($this->closed) {
            throw $this->closedError();
        }
    }

    private function requestBytes(string $method, string $target, ?string $body): string
    {
        $lines = [
            $method . ' ' . $target . ' HTTP/1.1',
            'Host: litebackup',
            Wire::SDK_VERSION_HEADER . ': ' . Wire::SDK_VERSION_VALUE,
        ];
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        return implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
    }

    private function writeAll(TransportConnection $connection, string $bytes, ?float $deadline): void
    {
        $stream = $connection->requireStream();
        while ($bytes !== '') {
            $this->remaining($deadline);
            $written = @fwrite($stream, $bytes);
            if ($written === false) {
                throw new TransportConnectionBroken('the daemon closed the connection before the request was sent');
            }
            if ($written === 0) {
                $this->wait($stream, false, $deadline);
                continue;
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * Reads more bytes into the connection's buffer. At the end of the
     * stream it returns false when endAllowed, as for a body that ends with
     * the connection, and throws TransportConnectionBroken otherwise.
     */
    private function fill(TransportConnection $connection, ?float $deadline, bool $endAllowed = false): bool
    {
        $stream = $connection->requireStream();
        while (true) {
            // Checked before every read, so an answer that keeps arriving
            // still ends at the deadline.
            $this->remaining($deadline);
            $chunk = @fread($stream, self::READ_SIZE);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                if ($endAllowed) {
                    return false;
                }
                throw new TransportConnectionBroken('the daemon closed the connection before answering');
            }
            if ($chunk === '') {
                $this->wait($stream, true, $deadline);
                continue;
            }
            $connection->answerStarted = true;
            $connection->buffer .= $chunk;
            return true;
        }
    }

    private function readLine(TransportConnection $connection, ?float $deadline): string
    {
        while (true) {
            $lineEnd = strpos($connection->buffer, "\r\n");
            if ($lineEnd !== false) {
                $line = substr($connection->buffer, 0, $lineEnd);
                $connection->buffer = substr($connection->buffer, $lineEnd + 2);
                return $line;
            }
            if (strlen($connection->buffer) > self::HEADER_LIMIT) {
                throw new TransportConnectionBroken("the daemon's answer has a header line that is too long");
            }
            $this->fill($connection, $deadline);
        }
    }

    private function readBytes(TransportConnection $connection, int $count, ?float $deadline): string
    {
        while (strlen($connection->buffer) < $count) {
            $this->fill($connection, $deadline);
        }
        $bytes = substr($connection->buffer, 0, $count);
        $connection->buffer = substr($connection->buffer, $count);
        return $bytes;
    }

    /** @return array{TransportAnswer, bool} the answer and whether the connection may carry another request */
    private function exchange(
        TransportConnection $connection,
        bool $reused,
        string $method,
        string $target,
        ?string $body,
        ?float $deadline,
    ): array {
        $connection->buffer = '';
        $this->remaining($deadline);
        if (!$reused) {
            $this->connect($connection, $deadline);
        }
        $this->writeAll($connection, $this->requestBytes($method, $target, $body), $deadline);
        while (true) {
            [$status, $headers, $version] = $this->readHead($connection, $deadline);
            // An informational answer, such as 100 Continue, precedes the real one.
            if ($status >= 100 && $status <= 199) {
                continue;
            }
            [$answerBody, $framed] = $this->readBody($connection, $headers, $deadline);
            $reusable = $framed && $version === 'HTTP/1.1' && strtolower($headers['connection'] ?? '') !== 'close';
            $answer = new TransportAnswer($status, $headers[Wire::CLIENT_VERSION_HEADER] ?? '', $answerBody);
            return [$answer, $reusable];
        }
    }

    /** @return array{int, array<string, string>, string} */
    private function readHead(TransportConnection $connection, ?float $deadline): array
    {
        $statusLine = $this->readLine($connection, $deadline);
        if (preg_match('~\A(HTTP/1\.[01]) (\d{3})~', $statusLine, $match) !== 1) {
            throw new TransportConnectionBroken(
                'the daemon answered with ' . json_encode(Wire::validText(substr($statusLine, 0, 40))) . ', not HTTP',
            );
        }
        $headers = [];
        $size = strlen($statusLine);
        while (true) {
            $line = $this->readLine($connection, $deadline);
            if ($line === '') {
                break;
            }
            $size += strlen($line);
            if ($size > self::HEADER_LIMIT) {
                throw new TransportConnectionBroken("the daemon's answer has too many headers");
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return [(int) $match[2], $headers, $match[1]];
    }

    private function checkSize(int $size): void
    {
        if ($size > Wire::RESPONSE_LIMIT) {
            throw new LitebackupError(ErrorCode::INVALID_RESPONSE, "the daemon's answer is too large");
        }
    }

    /**
     * The body and whether its length was framed, so the connection can carry another request.
     *
     * @param array<string, string> $headers
     * @return array{string, bool}
     */
    private function readBody(TransportConnection $connection, array $headers, ?float $deadline): array
    {
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            return [$this->readChunked($connection, $deadline), true];
        }
        if (array_key_exists('content-length', $headers)) {
            $length = $headers['content-length'];
            if (preg_match('/\A\d+\z/', $length) !== 1) {
                throw new TransportConnectionBroken("the daemon's answer has a bad Content-Length");
            }
            $this->checkSize((int) $length);
            return [$this->readBytes($connection, (int) $length, $deadline), true];
        }
        return [$this->readToEnd($connection, $deadline), false];
    }

    private function readChunked(TransportConnection $connection, ?float $deadline): string
    {
        $body = '';
        while (true) {
            $sizeLine = trim(explode(';', $this->readLine($connection, $deadline), 2)[0]);
            if (preg_match('/\A[0-9A-Fa-f]+\z/', $sizeLine) !== 1) {
                throw new TransportConnectionBroken("the daemon's answer has a bad chunk size");
            }
            $size = (int) hexdec($sizeLine);
            if ($size === 0) {
                // Trailers, up to the blank line.
                while ($this->readLine($connection, $deadline) !== '');
                return $body;
            }
            $this->checkSize(strlen($body) + $size);
            $body .= $this->readBytes($connection, $size, $deadline);
            if ($this->readLine($connection, $deadline) !== '') {
                throw new TransportConnectionBroken("the daemon's answer has a bad chunk end");
            }
        }
    }

    private function readToEnd(TransportConnection $connection, ?float $deadline): string
    {
        do {
            $this->checkSize(strlen($connection->buffer));
        } while ($this->fill($connection, $deadline, true));
        $body = $connection->buffer;
        $connection->buffer = '';
        return $body;
    }
}
