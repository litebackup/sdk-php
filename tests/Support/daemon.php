<?php

declare(strict_types=1);

/*
 * A fake litebackup daemon for the PHP SDK's tests, run as a child process
 * (FakeDaemon starts it with proc_open), because PHP has no threads to answer
 * while the client under test blocks. It listens on a unix socket, serves
 * many connections at once with stream_select, and answers from a scripted
 * plan, so a test whose client blocks in a call, as a failover's follow()
 * does, needs no action from the parent while it waits.
 *
 * Usage: php daemon.php PLAN-FILE
 *
 * The plan is a JSON object:
 *   socket          the socket path to listen on
 *   log             a file that gets one JSON line for each request received
 *   client_version  the Litebackup-Client-Version header; null sends none
 *   ordered         answers are used strictly in order, and a request that
 *                   does not match the next one gets a 500 unexpected
 *   answers         a list of answers, each with:
 *     match           {method, path, query}: the request it answers, any part
 *                     left out matching anything (the query as a whole map)
 *     status, body    the status and the body text, or json: a value to encode
 *     content_type    application/json by default
 *     headers         extra header lines
 *     raw             bytes to write instead of an answer built from the above
 *     trickle         {bytes, seconds}: writes bytes one at a time, one every
 *                     seconds, then keeps writing spaces, to test deadlines
 *     delay_seconds   how long to wait before answering
 *     close           close the connection instead of answering, or after raw
 *     uses            how many requests it answers; 1 by default, 0 for any number
 *     after_requests  answers only once this many requests arrived
 *     after_seconds   answers only once this many seconds passed after the start
 *     stop            stop serving once this answer is sent: the socket and
 *                     every connection close, as when a daemon exits
 *     serve_after_seconds  with stop: listen again on the same path after this long
 *
 * The connect probe (GET /v1/status/show?limit=1) is answered without the
 * plan, after probe_delay_seconds when the plan sets it, and not logged.
 * Commands arrive on standard input as JSON lines and
 * are acknowledged on standard output, {"ack": N} for the Nth:
 *   {"command": "stop"}                      stop serving
 *   {"command": "serve"}                     serve again
 *   {"command": "answers", "answers": [...]} add answers at the end of the plan
 *   {"command": "exit"}                      stop and exit
 */

const STATUS_BODY = '{"session":"contract","server":"http://example","client_version":"1.6.0",'
    . '"session_id":"s","watch":"inotify","databases":[]}';
const READ_SIZE = 65536;

/**
 * One connection and what it waits for.
 */
final class DaemonConnection
{
    public string $received = '';
    /** @var list<array{float, string, bool}> writes to make: when, the bytes, and whether to close after */
    public array $writes = [];
    /** @var array{float, string}|null a trickle's next byte time and the bytes left */
    public ?array $trickle = null;
    /** How long a trickle waits between bytes. */
    public float $trickleSeconds = 0.0;
    /** @var array<string, mixed>|null the request that waits for an answer whose gate is closed */
    public ?array $waiting = null;

    /** @param resource $stream */
    public function __construct(public $stream) {}
}

final class Daemon
{
    /** @var resource|null */
    private $server = null;
    /** @var array<int, DaemonConnection> */
    private array $connections = [];
    /** @var list<array<string, mixed>> */
    private array $answers;
    /** @var array<int, int> how often each answer was used */
    private array $uses = [];
    private int $requestCount = 0;
    private int $nextOrdered = 0;
    private float $started;
    private ?float $serveTime = null;
    private int $commandCount = 0;
    private string $commandBuffer = '';
    private bool $exiting = false;

    /** @param array<string, mixed> $plan */
    public function __construct(private array $plan)
    {
        $this->answers = array_values($plan['answers'] ?? []);
        $this->started = hrtime(true) / 1e9;
    }

    public function serve(): void
    {
        if ($this->server !== null) {
            return;
        }
        @unlink($this->plan['socket']);
        $server = stream_socket_server('unix://' . $this->plan['socket'], $errorNumber, $errorText);
        if ($server === false) {
            fwrite(STDERR, "listening failed: {$errorText}\n");
            exit(1);
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->serveTime = null;
    }

    public function stop(?float $serveAfterSeconds = null): void
    {
        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
        foreach ($this->connections as $connection) {
            @stream_socket_shutdown($connection->stream, STREAM_SHUT_RDWR);
            @fclose($connection->stream);
        }
        $this->connections = [];
        @unlink($this->plan['socket']);
        $this->serveTime = $serveAfterSeconds === null ? null : $this->now() + $serveAfterSeconds;
    }

    public function loop(): void
    {
        stream_set_blocking(STDIN, false);
        $inputOpen = true;
        while (!$this->exiting) {
            $now = $this->now();
            if ($this->serveTime !== null && $now >= $this->serveTime) {
                $this->serve();
            }
            $this->writeScheduledBytes($now);
            $this->answerWaiting();
            $readable = [];
            if ($this->server !== null) {
                $readable[] = $this->server;
            }
            if ($inputOpen) {
                $readable[] = STDIN;
            }
            foreach ($this->connections as $connection) {
                $readable[] = $connection->stream;
            }
            $writable = null;
            $failed = null;
            if (@stream_select($readable, $writable, $failed, 0, 10_000) === false) {
                continue;
            }
            foreach ($readable as $stream) {
                if ($stream === STDIN) {
                    $inputOpen = $this->readCommands();
                } elseif ($stream === $this->server) {
                    $accepted = @stream_socket_accept($this->server, 0);
                    if ($accepted !== false) {
                        stream_set_blocking($accepted, false);
                        $this->connections[(int) $accepted] = new DaemonConnection($accepted);
                    }
                } else {
                    $this->readConnection($this->connections[(int) $stream] ?? null);
                }
            }
        }
        $this->stop();
    }

    private function now(): float
    {
        return hrtime(true) / 1e9 - $this->started;
    }

    private function readCommands(): bool
    {
        $chunk = fread(STDIN, READ_SIZE);
        if ($chunk === false || ($chunk === '' && feof(STDIN))) {
            // The test process ended: so does the daemon.
            $this->exiting = true;
            return false;
        }
        $this->commandBuffer .= $chunk;
        while (($end = strpos($this->commandBuffer, "\n")) !== false) {
            $line = substr($this->commandBuffer, 0, $end);
            $this->commandBuffer = substr($this->commandBuffer, $end + 1);
            $command = json_decode($line, true);
            if (!is_array($command)) {
                continue;
            }
            match ($command['command'] ?? '') {
                'stop' => $this->stop(),
                'serve' => $this->serve(),
                'answers' => array_push($this->answers, ...array_values($command['answers'] ?? [])),
                'exit' => $this->exiting = true,
                default => null,
            };
            $this->commandCount++;
            fwrite(STDOUT, json_encode(['ack' => $this->commandCount]) . "\n");
        }
        return true;
    }

    private function readConnection(?DaemonConnection $connection): void
    {
        if ($connection === null) {
            return;
        }
        $chunk = @fread($connection->stream, READ_SIZE);
        if ($chunk === false || ($chunk === '' && feof($connection->stream))) {
            $this->closeConnection($connection);
            return;
        }
        $connection->received .= $chunk;
        while ($connection->waiting === null && ($request = $this->parseRequest($connection)) !== null) {
            $this->answerRequest($connection, $request);
        }
    }

    /** @return array<string, mixed>|null */
    private function parseRequest(DaemonConnection $connection): ?array
    {
        $headEnd = strpos($connection->received, "\r\n\r\n");
        if ($headEnd === false) {
            return null;
        }
        $lines = explode("\r\n", substr($connection->received, 0, $headEnd));
        [$method, $target] = explode(' ', array_shift($lines), 3);
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        if (strlen($connection->received) < $headEnd + 4 + $length) {
            return null;
        }
        $body = substr($connection->received, $headEnd + 4, $length);
        $connection->received = substr($connection->received, $headEnd + 4 + $length);
        [$path, $queryText] = array_pad(explode('?', $target, 2), 2, '');
        $query = [];
        foreach ($queryText === '' ? [] : explode('&', $queryText) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($key)] = rawurldecode($value);
        }
        return [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'body' => $body,
            'sdk_version' => $headers['litebackup-sdk-version'] ?? '',
            'received' => $this->now(),
            // The system-wide monotonic clock, which the test process shares.
            'monotonic' => hrtime(true) / 1e9,
        ];
    }

    /** @param array<string, mixed> $request */
    private function answerRequest(DaemonConnection $connection, array $request): void
    {
        $probe = $request['method'] === 'GET' && $request['path'] === '/v1/status/show'
            && $request['query'] === ['limit' => '1'];
        if ($probe) {
            $this->queueAnswer($connection, [
                'status' => 200,
                'body' => STATUS_BODY,
                'delay_seconds' => $this->plan['probe_delay_seconds'] ?? 0,
            ]);
            return;
        }
        $this->requestCount++;
        file_put_contents($this->plan['log'], json_encode($request) . "\n", FILE_APPEND);
        $connection->waiting = $request;
        $this->answerWaiting();
    }

    /** Answers every waiting request whose answer's gate is open. */
    private function answerWaiting(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->waiting === null) {
                continue;
            }
            $answer = $this->chooseAnswer($connection->waiting);
            if ($answer === false) {
                continue;
            }
            $connection->waiting = null;
            $this->queueAnswer($connection, $answer ?? [
                'status' => 500,
                'json' => ['code' => 'unexpected', 'message' => 'no answer for the request'],
            ]);
            while ($connection->waiting === null && ($request = $this->parseRequest($connection)) !== null) {
                $this->answerRequest($connection, $request);
            }
        }
    }

    /**
     * The answer for a request: the plan's, null when none matches, or false
     * while the matching answer's gate is closed.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null|false
     */
    private function chooseAnswer(array $request): array|false|null
    {
        $now = $this->now();
        $candidates = ($this->plan['ordered'] ?? false)
            ? (isset($this->answers[$this->nextOrdered]) ? [$this->nextOrdered => $this->answers[$this->nextOrdered]] : [])
            : $this->answers;
        foreach ($candidates as $index => $answer) {
            $limit = $answer['uses'] ?? 1;
            if ($limit !== 0 && ($this->uses[$index] ?? 0) >= $limit) {
                continue;
            }
            if (!$this->matches($answer['match'] ?? [], $request)) {
                if ($this->plan['ordered'] ?? false) {
                    return ['status' => 500, 'json' => ['code' => 'unexpected', 'message' => json_encode($request)]];
                }
                continue;
            }
            if (($answer['after_requests'] ?? 0) > $this->requestCount || ($answer['after_seconds'] ?? 0) > $now) {
                return false;
            }
            $this->uses[$index] = ($this->uses[$index] ?? 0) + 1;
            if (($this->plan['ordered'] ?? false) && ($limit === 0 ? false : $this->uses[$index] >= $limit)) {
                $this->nextOrdered++;
            }
            return $answer;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $request
     */
    private function matches(array $match, array $request): bool
    {
        foreach (['method', 'path', 'query'] as $part) {
            if (array_key_exists($part, $match) && $match[$part] != $request[$part]) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $answer */
    private function queueAnswer(DaemonConnection $connection, array $answer): void
    {
        $when = $this->now() + (float) ($answer['delay_seconds'] ?? 0);
        if (($answer['close'] ?? false) && !isset($answer['raw'])) {
            $connection->writes[] = [$when, '', true];
        } elseif (isset($answer['trickle'])) {
            $connection->trickle = [$when, (string) $answer['trickle']['bytes']];
            $connection->trickleSeconds = (float) $answer['trickle']['seconds'];
        } elseif (isset($answer['raw'])) {
            // raw with close writes the bytes and then closes, ending a body that runs to the end of the stream.
            $connection->writes[] = [$when, (string) $answer['raw'], (bool) ($answer['close'] ?? false)];
        } else {
            $body = array_key_exists('json', $answer) ? json_encode($answer['json']) : (string) ($answer['body'] ?? '');
            $head = ['HTTP/1.1 ' . (int) ($answer['status'] ?? 200) . ' Answer'];
            $clientVersion = array_key_exists('client_version', $this->plan) ? $this->plan['client_version'] : '1.6.0';
            if ($clientVersion !== null) {
                $head[] = 'Litebackup-Client-Version: ' . $clientVersion;
            }
            $head[] = 'Content-Type: ' . ($answer['content_type'] ?? 'application/json');
            $head[] = 'Content-Length: ' . strlen($body);
            foreach ($answer['headers'] ?? [] as $line) {
                $head[] = $line;
            }
            $connection->writes[] = [$when, implode("\r\n", $head) . "\r\n\r\n" . $body, false];
        }
        if ($answer['stop'] ?? false) {
            $connection->writes[] = [$when, '', 'stop:' . ($answer['serve_after_seconds'] ?? '')];
        }
    }

    private function writeScheduledBytes(float $now): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->trickle !== null && $connection->trickle[0] <= $now) {
                [, $bytes] = $connection->trickle;
                $byte = $bytes === '' ? ' ' : $bytes[0];
                @fwrite($connection->stream, $byte);
                $connection->trickle = [$now + $connection->trickleSeconds, substr($bytes, 1)];
            }
            while ($connection->writes !== [] && $connection->writes[0][0] <= $now) {
                [, $bytes, $after] = array_shift($connection->writes);
                if ($bytes !== '') {
                    $this->writeAll($connection, $bytes);
                }
                if ($after === true) {
                    $this->closeConnection($connection);
                    break;
                }
                if (is_string($after) && str_starts_with($after, 'stop:')) {
                    $serveAfter = substr($after, 5);
                    $this->stop($serveAfter === '' ? null : (float) $serveAfter);
                    return;
                }
            }
        }
    }

    private function writeAll(DaemonConnection $connection, string $bytes): void
    {
        stream_set_blocking($connection->stream, true);
        while ($bytes !== '') {
            $written = @fwrite($connection->stream, $bytes);
            if ($written === false || $written === 0) {
                break;
            }
            $bytes = substr($bytes, $written);
        }
        stream_set_blocking($connection->stream, false);
    }

    private function closeConnection(DaemonConnection $connection): void
    {
        unset($this->connections[(int) $connection->stream]);
        @fclose($connection->stream);
    }
}

$plan = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$daemon = new Daemon($plan);
$daemon->serve();
fwrite(STDOUT, "ready\n");
$daemon->loop();
