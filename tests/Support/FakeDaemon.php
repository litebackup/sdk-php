<?php

declare(strict_types=1);

namespace Litebackup\Tests\Support;

use RuntimeException;

/**
 * A fake litebackup daemon in a child PHP process (daemon.php), started
 * with a plan of answers and controlled over its standard input. PHP has no
 * threads, so the answers are scripted ahead: a call that blocks in the test
 * still gets them.
 */
final class FakeDaemon
{
    public readonly string $directory;
    public readonly string $socketPath;
    private readonly string $logPath;
    /** @var resource */
    private $child;
    /** @var resource */
    private $commands;
    /** @var resource */
    private $acknowledgements;
    private int $commandsSent = 0;

    /**
     * @param list<array<string, mixed>> $answers
     * @param array<string, mixed> $options ordered, client_version
     */
    public function __construct(array $answers, array $options = [])
    {
        $directory = sys_get_temp_dir() . '/lbp-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700);
        $this->directory = $directory;
        $this->socketPath = $directory . '/client.sock';
        $this->logPath = $directory . '/requests.jsonl';
        touch($this->logPath);
        $plan = ['socket' => $this->socketPath, 'log' => $this->logPath, 'answers' => $answers] + $options;
        $planPath = $directory . '/plan.json';
        file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));
        $pipes = [];
        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/daemon.php', $planPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/daemon.log', 'a']],
            $pipes,
        );
        if ($child === false) {
            throw new RuntimeException('starting the fake daemon failed');
        }
        $this->child = $child;
        /** @var array{0: resource, 1: resource} $pipes */
        $this->commands = $pipes[0];
        $this->acknowledgements = $pipes[1];
        $ready = fgets($this->acknowledgements);
        if ($ready !== "ready\n") {
            throw new RuntimeException('the fake daemon did not start: ' . (string) @file_get_contents($directory . '/daemon.log'));
        }
    }

    /**
     * An answer with a JSON body.
     *
     * @param array<string, mixed> $match
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function json(array $match, mixed $body, int $status = 200, array $extra = []): array
    {
        return ['match' => $match, 'status' => $status, 'json' => $body] + $extra;
    }

    /**
     * The requests received, the connect probe left out, each with method,
     * path, query, body, sdk_version, and received (seconds after the start).
     *
     * @phpstan-impure the daemon appends to its log while the test runs
     * @return list<array{method: string, path: string, query: array<string, string>, body: string, sdk_version: string, received: float, monotonic: float}>
     */
    public function requests(): array
    {
        $requests = [];
        foreach (file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{method: string, path: string, query: array<string, string>, body: string, sdk_version: string, received: float, monotonic: float} $request */
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $request;
        }
        return $requests;
    }

    /**
     * The JSON bodies of the requests received.
     *
     * @return list<mixed>
     */
    public function bodies(): array
    {
        return array_map(
            static fn(array $request): mixed => $request['body'] === '' ? null : json_decode($request['body'], true),
            $this->requests(),
        );
    }

    /**
     * Sends a command and waits for the daemon to acknowledge it.
     *
     * @param array<string, mixed> $command
     */
    public function command(array $command): void
    {
        fwrite($this->commands, json_encode($command, JSON_THROW_ON_ERROR) . "\n");
        $this->commandsSent++;
        $acknowledgement = fgets($this->acknowledgements);
        if ($acknowledgement !== json_encode(['ack' => $this->commandsSent]) . "\n") {
            throw new RuntimeException('the fake daemon did not acknowledge ' . json_encode($command));
        }
    }

    /** Closes the socket and every connection, as a daemon that exits does. */
    public function stop(): void
    {
        $this->command(['command' => 'stop']);
    }

    /** Listens again on the same path. */
    public function serve(): void
    {
        $this->command(['command' => 'serve']);
    }

    /**
     * Adds answers at the end of the plan.
     *
     * @param list<array<string, mixed>> $answers
     */
    public function addAnswers(array $answers): void
    {
        $this->command(['command' => 'answers', 'answers' => $answers]);
    }

    public function close(): void
    {
        @fwrite($this->commands, json_encode(['command' => 'exit']) . "\n");
        @fclose($this->commands);
        @fclose($this->acknowledgements);
        proc_close($this->child);
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }
}
