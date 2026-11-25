<?php

declare(strict_types=1);

namespace Litebackup\Tests\Support;

use Litebackup\Client;
use Litebackup\Version;
use PHPUnit\Framework\TestCase;

/**
 * A test with fake daemons and a working directory of its own. Each daemon
 * closes after the test, which first checks that every call sent this SDK's
 * version header.
 */
abstract class DaemonTestCase extends TestCase
{
    /** @var list<FakeDaemon> */
    private array $daemons = [];
    /** @var list<Client> */
    private array $clients = [];
    protected string $workingDirectory = '';
    /** Whether the requests must carry this SDK's version header; raw requests do not. */
    protected bool $sdkRequestsOnly = true;
    private string $previousDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDirectory = (string) getcwd();
        $directory = sys_get_temp_dir() . '/litebackup-test-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o755);
        $this->workingDirectory = (string) realpath($directory);
        chdir($this->workingDirectory);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->daemons as $daemon) {
            $versions = array_unique(array_column($daemon->requests(), 'sdk_version'));
            $daemon->close();
            if (!$this->sdkRequestsOnly) {
                continue;
            }
            self::assertSame([], array_values(array_diff($versions, ['php/' . Version::VERSION])));
        }
        chdir($this->previousDirectory);
        self::removeTree($this->workingDirectory);
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $answers
     * @param array<string, mixed> $options
     */
    protected function startDaemon(array $answers, array $options = []): FakeDaemon
    {
        $daemon = new FakeDaemon($answers, $options);
        $this->daemons[] = $daemon;
        return $daemon;
    }

    protected function clientTo(FakeDaemon $daemon): Client
    {
        $client = new Client(socketPath: $daemon->socketPath);
        $this->clients[] = $client;
        return $client;
    }

    /**
     * The JSON body of a request the fake daemon received, as an object.
     *
     * @param array{body: string} $request
     * @return array<string, mixed>
     */
    public static function requestBody(array $request): array
    {
        $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    public static function removeTree(string $path): void
    {
        if ($path === '' || (!file_exists($path) && !is_link($path))) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0o755);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }
}
