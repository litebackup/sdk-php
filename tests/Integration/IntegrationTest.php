<?php

declare(strict_types=1);

namespace Litebackup\Tests\Integration;

use DateTimeImmutable;
use Litebackup\Client;
use Litebackup\Failover;
use Litebackup\Integrations\PdoSqlite;
use Litebackup\LeaseCleanupError;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\DaemonTestCase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Against a real server and daemons: set LITEBACKUP_INTEGRATION=1 and
 * LITEBACKUP_PATH to a static litebackup binary from scripts/build, as
 * scripts/test --integration does. The server runs --development with a
 * local bucket, as the Python, Go, TypeScript, and Ruby suites use.
 */
final class IntegrationTest extends TestCase
{
    private string $binary = '';
    private string $directory = '';
    private string $tag = '';
    private string $serverDirectory = '';
    private string $address = '';
    /** @var array<string, string> */
    private array $environment = [];
    /** @var resource|null */
    private $server = null;
    /** @var list<Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        if (getenv('LITEBACKUP_INTEGRATION') !== '1' || (string) getenv('LITEBACKUP_PATH') === '') {
            self::markTestSkipped('set LITEBACKUP_INTEGRATION=1 and LITEBACKUP_PATH');
        }
        $this->binary = (string) getenv('LITEBACKUP_PATH');
        $this->directory = sys_get_temp_dir() . '/lbph' . bin2hex(random_bytes(3));
        mkdir($this->directory, 0o700);
        $this->tag = basename($this->directory);
        $this->serverDirectory = $this->directory . '/server';
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $this->address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $environment = [];
        foreach (getenv() as $variable => $value) {
            $environment[(string) $variable] = (string) $value;
        }
        $this->environment = array_merge($environment, [
            'XDG_RUNTIME_DIR' => $this->directory . '/run',
            'XDG_STATE_HOME' => $this->directory . '/state',
            'LITEBACKUP_API_KEY' => '',
            'LITEBACKUP_SESSION' => '',
        ]);
        $log = $this->directory . '/server.log';
        $server = proc_open([
            $this->binary, 'server', 'run', '--development',
            '--data-directory', $this->serverDirectory,
            '--listen-address', $this->address, '--archive-delay', '1s', '--minimum-sync-interval', '0s',
            '--session-lease', '3s', '--session-heartbeat', '1s',
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $this->environment);
        self::assertNotFalse($server);
        $this->server = $server;
        self::eventually(30, fn() => $this->joinToken('probe'));
        $this->admin('bucket', 'create', '--provider', 's3', '--endpoint', 'file://' . $this->directory . '/bucket', '--bucket', 'bucket', '--name', 'local');
        $this->admin('setup', 'complete', 'key_backup');
        $this->admin('setup', 'complete', 'retention');
    }

    protected function tearDown(): void
    {
        if ($this->server === null) {
            return;
        }
        // Stop the daemons before the server, because their shutdown ends their sessions on it.
        foreach ($this->clients as $client) {
            try {
                $client->session->shutdown(durability: 'none');
            } catch (LitebackupError) {
            } finally {
                $client->close();
            }
        }
        foreach ($this->pids('/' . preg_quote($this->tag, '/') . '/') as $pid) {
            posix_kill($pid, SIGKILL);
        }
        proc_terminate($this->server, SIGKILL);
        proc_close($this->server);
        DaemonTestCase::removeTree($this->directory);
    }

    private function admin(string ...$arguments): string
    {
        $command = [$this->binary, 'server', ...array_values($arguments), '--data-directory', $this->serverDirectory];
        $child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment);
        self::assertNotFalse($child);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($child) !== 0) {
            throw new RuntimeException('litebackup server ' . implode(' ', $arguments) . ' failed: ' . $output);
        }
        return $output;
    }

    private function joinToken(string $name): string
    {
        $created = json_decode($this->admin('join-token', 'create', '--name', $name, '--stdout-format', 'json'), true);
        self::assertIsArray($created);
        self::assertIsString($created['token'] ?? null);
        return $created['token'] . '@http://' . $this->address;
    }

    /** A session of this run; its name carries the tag, so stray daemons are found. */
    private function session(string $name): string
    {
        return $name . '-' . $this->tag;
    }

    /** A client of a new session that joins with its own join token, as an app on another machine would. */
    private function connect(string $name): Client
    {
        $client = new Client(apiKey: $this->joinToken($this->session($name)), session: $this->session($name), environment: $this->environment);
        $client->connect();
        $this->clients[] = $client;
        return $client;
    }

    /** A client of a session that joined before; its daemon keeps the join it made. */
    private function reconnect(string $name): Client
    {
        $client = new Client(session: $this->session($name), environment: $this->environment);
        $client->connect();
        $this->clients[] = $client;
        return $client;
    }

    /** @return list<int> the PIDs whose command line matches pattern, read from /proc */
    private function pids(string $pattern): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
            $command = trim(str_replace("\0", ' ', (string) @file_get_contents($cmdline)));
            if (preg_match($pattern, $command) === 1) {
                $pids[] = (int) basename(dirname($cmdline));
            }
        }
        return $pids;
    }

    /** Stops a session's daemon with SIGKILL, so its lease runs out on the server. */
    private function kill(string $name): void
    {
        foreach ($this->pids('/daemon --session ' . preg_quote($this->session($name), '/') . '$/') as $pid) {
            posix_kill($pid, SIGKILL);
        }
    }

    private function path(string ...$elements): string
    {
        $path = $this->directory . '/' . implode('/', $elements);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        return $path;
    }

    /**
     * Calls check until it stops throwing or seconds pass.
     *
     * @template R
     * @param callable(): R $check
     * @return R
     */
    private static function eventually(float $seconds, callable $check): mixed
    {
        $deadline = hrtime(true) / 1e9 + $seconds;
        while (true) {
            try {
                return $check();
            } catch (Throwable $error) {
                if (hrtime(true) / 1e9 > $deadline) {
                    throw $error;
                }
                usleep(250_000);
            }
        }
    }

    private static function addItems(string $path, int $count): void
    {
        $database = new PDO('sqlite:' . $path);
        $database->exec('CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, body BLOB)');
        for ($index = 0; $index < $count; $index++) {
            $database->exec('INSERT INTO items (body) VALUES (randomblob(2000))');
        }
    }

    private static function countItems(string $path): int
    {
        $database = new PDO('sqlite:' . $path);
        $count = $database->query('SELECT count(*) FROM items');
        self::assertNotFalse($count);
        return (int) $count->fetchColumn();
    }

    public function testBacksUpRegistersAgainRestoresReplicatesAcquiresAndReleases(): void
    {
        $web1 = $this->connect('web-1');
        $web2 = $this->connect('web-2');
        $reader = $this->connect('reader');
        self::assertNotSame('', $web1->clientVersion());

        $mainPath = $this->path('web-1', 'main.db');
        self::addItems($mainPath, 10);
        $database = new PDO('sqlite:' . $mainPath);
        $rule = $web1->backups->start(PdoSqlite::databasePath($database));
        unset($database);
        self::assertSame('main.db', $rule->name);
        self::assertSame($rule->id, $web1->backups->start($mainPath)->id);
        try {
            $web1->backups->start($mainPath, maximumLocalDatabases: 4);
            self::fail('changed settings were saved');
        } catch (LitebackupError $error) {
            self::assertSame('rule_exists', $error->errorCode);
            self::assertIsArray($error->details['rule']);
            self::assertSame($rule->id, $error->details['rule']['id']);
        }
        $web1->databases->sync($rule->name);
        self::addItems($mainPath, 5);
        $synced = $web1->databases->sync($rule->name);
        $versions = $web1->databases->versions('main.db');
        self::assertGreaterThanOrEqual(2, count($versions));
        self::assertSame($synced->syncedServer->version, $versions[count($versions) - 1]->version);
        $restoredPath = $this->path('restored', 'main.db');
        $restored = $web2->databases->restore('main.db', path: $restoredPath, version: $versions[0]->version);
        self::assertSame($versions[0]->version, $restored->version);
        self::assertSame(10, self::countItems($restoredPath));
        try {
            $web2->databases->restore('main.db', path: $restoredPath);
            self::fail('an existing file was overwritten');
        } catch (LitebackupError $error) {
            self::assertSame('file_exists', $error->errorCode);
        }
        $headPath = $this->path('restored', 'head.db');
        $web2->databases->restore('main.db', path: new SplFileInfo($headPath), time: new DateTimeImmutable());
        self::assertSame(15, self::countItems($headPath));

        $replicaPath = $this->path('reader', 'main.db');
        $replica = $reader->replicas->start('main.db', path: $replicaPath);
        self::assertSame('replica', $replica->role);
        self::assertSame(15, self::countItems($replicaPath));
        self::assertCount(1, $reader->replicas->list()->replicas);

        $tenantPath = $this->path('web-1', '42.db');
        self::addItems($tenantPath, 4);
        $acquired = $web1->databases->acquire('tenants/42.db', path: $tenantPath, maintenance: true);
        self::assertSame('created', $acquired->decision);
        self::assertTrue($web1->databases->writer('tenants/42.db')->thisMachine);
        self::addItems($tenantPath, 2);
        $released = $web1->databases->release('tenants/42.db', durability: 'server');
        self::assertGreaterThanOrEqual(2, $released->database->syncedServer->version);
        $movedPath = $this->path('web-2', '42.db');
        self::assertSame('downloaded', $web2->databases->acquire('tenants/42.db', path: $movedPath)->decision);
        self::assertSame(6, self::countItems($movedPath));
        self::addItems($movedPath, 1);
        $web2->databases->release('tenants/42.db', durability: 'server');

        // Writes the head never saw, made after web-2 moved the head on,
        // diverge: the copy is refused and left as it is, unless discardLocal.
        self::addItems($tenantPath, 3);
        try {
            $web1->databases->acquire('tenants/42.db', path: $tenantPath);
            self::fail('a diverged copy was acquired');
        } catch (LitebackupError $error) {
            self::assertSame('conflict', $error->errorCode);
        }
        self::assertSame(9, self::countItems($tenantPath));
        $replaced = $web1->databases->acquire('tenants/42.db', path: $tenantPath, discardLocal: true);
        self::assertSame('replaced', $replaced->decision);
        self::assertSame(7, self::countItems($tenantPath));
        $web1->databases->release('tenants/42.db', durability: 'server');
    }

    /**
     * A pool lease writes through the PDO adapter, rolls back what it did
     * not commit, and offloads on release once the bucket has the version.
     */
    public function testPoolLeasesADatabaseAndOffloadsItOnRelease(): void
    {
        $worker = $this->connect('worker');
        $path = $this->path('worker', '7.sqlite');
        $pool = $worker->databases->pool(PdoSqlite::adapter());
        $pool->lease('agents/7.sqlite', static function (object $connection): void {
            self::assertInstanceOf(PDO::class, $connection);
            $connection->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, body BLOB)');
            for ($index = 0; $index < 3; $index++) {
                $connection->exec("INSERT INTO items (body) VALUES ('php')");
            }
            $connection->exec('BEGIN');
            $connection->exec("INSERT INTO items (body) VALUES ('never committed')");
        }, path: $path, durability: 'cloud', offload: true);
        $pool->close();
        $status = $worker->databases->get('agents/7.sqlite');
        self::assertSame('offloaded', $status->state);
        self::assertSame($status->syncedServer->version, $status->syncedCloud->version);
        $back = $this->path('back', '7.sqlite');
        $worker->databases->restore('agents/7.sqlite', path: $back);
        self::assertSame(3, self::countItems($back));
    }

    /**
     * A close that fails never releases: the database stays acquired until
     * every connection is closed and release is called. Here a statement
     * the work kept holds the PDO connection.
     */
    public function testCloseFailureKeepsTheDatabaseAcquired(): void
    {
        $worker = $this->connect('close');
        $path = $this->path('close', '9.sqlite');
        $pool = $worker->databases->pool(PdoSqlite::adapter());
        $retainedStatement = null;
        $pool->lease('agents/9.sqlite', static function (object $connection) use (&$retainedStatement): void {
            self::assertInstanceOf(PDO::class, $connection);
            $connection->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, body BLOB)');
            $retainedStatement = $connection->query('SELECT count(*) FROM items');
        }, path: $path, durability: 'server');
        try {
            $pool->close();
            self::fail('the pool closed');
        } catch (LeaseCleanupError $error) {
            self::assertSame(['lease_cleanup_failed', 'close'], [$error->errorCode, $error->step]);
            self::assertSame(['step' => 'close', 'name' => 'agents/9.sqlite', 'path' => $path, 'acquired' => true], $error->details);
        }
        self::assertTrue($worker->databases->writer('agents/9.sqlite')->thisMachine);
        $retainedStatement = null;
        $pool->release('agents/9.sqlite');
        self::assertFalse($worker->databases->writer('agents/9.sqlite')->thisMachine);
    }

    /**
     * The origin dies and the replica, following, takes over within
     * seconds of the 3 s lease; the old origin comes back with writes the
     * server lacks and is refused, its file left as it is.
     */
    public function testFailsOverAndDeniesTheOldOriginsDivergedFile(): void
    {
        $first = $this->connect('first');
        $second = $this->connect('second');
        $firstPath = $this->path('first', 'shared.db');
        self::addItems($firstPath, 5);
        $firstFailover = $first->failovers->start($firstPath, name: 'shared.db');
        self::assertTrue($firstFailover->isOrigin());
        $first->databases->sync('shared.db');
        $secondPath = $this->path('second', 'shared.db');
        $promoted = false;
        $secondFailover = $second->failovers->start($secondPath, name: 'shared.db', onRoleChange: static function (string $role) use (&$promoted): void {
            $promoted = $promoted || $role === Failover::ORIGIN;
        });
        self::assertSame(Failover::REPLICA, $secondFailover->role());
        self::eventually(30, fn() => self::assertSame(5, self::countItems($secondPath)));

        $this->kill('first');
        $killed = hrtime(true) / 1e9;
        // The old origin's follower loses its daemon, so its role is unknown at once.
        $firstFailover->follow(2.0);
        self::assertSame(Failover::UNKNOWN, $firstFailover->role());
        while (!$promoted && hrtime(true) / 1e9 - $killed < 30) {
            $secondFailover->follow(1.0);
        }
        self::assertTrue($promoted, 'the replica never took over');
        self::assertLessThan(6.0, hrtime(true) / 1e9 - $killed);
        self::assertTrue($secondFailover->isOrigin());
        // The old origin's app kept writing while its daemon was gone.
        self::addItems($firstPath, 3);

        $restarted = $this->reconnect('first');
        try {
            $restarted->failovers->start($firstPath, name: 'shared.db');
        } catch (LitebackupError $error) {
            self::assertSame('conflict', $error->errorCode);
        }
        self::eventually(60, function () use ($restarted, $firstPath): void {
            $denied = array_filter(
                $restarted->events->page(limit: 10_000)->events,
                static fn($event): bool => $event->type === 'denied' && $event->name === 'shared.db' && $event->path === $firstPath,
            );
            self::assertNotSame([], $denied, 'no denied event naming the file yet');
        });
        self::assertSame(8, self::countItems($firstPath));
        $secondFailover->follow(1.5);
        self::assertTrue($secondFailover->isOrigin());
        $secondFailover->stop();
    }
}
