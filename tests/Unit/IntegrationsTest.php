<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\ErrorCode;
use Litebackup\Integrations\Laravel\LitebackupServiceProvider;
use Litebackup\Integrations\PdoSqlite;
use Litebackup\Integrations\Sqlite3;
use Litebackup\Integrations\SqliteFile;
use Litebackup\LeaseCleanupError;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\DaemonTestCase;
use Litebackup\Tests\Support\FakeDaemon;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use SQLite3 as SqliteDatabase;

/** The PDO and SQLite3 adapters, SQLite file names, and the Laravel provider's discovery. */
final class IntegrationsTest extends DaemonTestCase
{
    /** @return array<string, array{string, string}> */
    public static function filenames(): array
    {
        return [
            'a relative file' => ['data/app.sqlite', '/base/data/app.sqlite'],
            'an absolute file' => ['/var/lib/app/app.sqlite', '/var/lib/app/app.sqlite'],
            'a file URI' => ['file:data/app.sqlite?cache=shared', '/base/data/app.sqlite'],
            'a localhost URI' => ['file://localhost/var/lib/app/a%20b.sqlite', '/var/lib/app/a b.sqlite'],
            'a plus in a URI' => ['file:/var/lib/app/a+b.sqlite', '/var/lib/app/a+b.sqlite'],
        ];
    }

    #[DataProvider('filenames')]
    public function testFilenameResolvesAsSqliteDoes(string $filename, string $file): void
    {
        self::assertSame($file, SqliteFile::databaseFile($filename, '/base'));
    }

    /** @return array<string, array{string}> */
    public static function filenamesWithoutAFile(): array
    {
        return [
            'memory' => [':memory:'],
            'empty' => [''],
            'a memory URI' => ['file:app?mode=memory'],
            'a memdb URI' => ['file:/app?vfs=memdb'],
            'another host' => ['file://backups.example.com/app.sqlite'],
        ];
    }

    #[DataProvider('filenamesWithoutAFile')]
    public function testFilenameWithoutAFileIsDenied(string $filename): void
    {
        try {
            SqliteFile::databaseFile($filename, '/base');
            self::fail('a file was found');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testPdoAdapterOpensExistingFilesOnly(): void
    {
        $adapter = PdoSqlite::adapter();
        $path = $this->workingDirectory . '/app.sqlite';
        try {
            $adapter->open($path);
            self::fail('a missing file was opened');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode);
        }
        self::assertFileDoesNotExist($path);
        touch($path);
        $connection = $adapter->open($path);
        self::assertInstanceOf(PDO::class, $connection);
        self::assertSame($path, PdoSqlite::databasePath($connection));
        unset($connection);
        try {
            PdoSqlite::adapter([PDO::ATTR_PERSISTENT => true]);
            self::fail('a persistent adapter was made');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
        try {
            PdoSqlite::databasePath(new PDO('sqlite::memory:'));
            self::fail('a memory database has a path');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT, $error->errorCode);
        }
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testPdoCloseRollsBackARawBegin(): void
    {
        $path = $this->workingDirectory . '/app.sqlite';
        $setup = new PDO('sqlite:' . $path);
        $setup->exec('CREATE TABLE steps (body TEXT)');
        unset($setup);
        $adapter = PdoSqlite::adapter();
        $connection = $adapter->open($path);
        self::assertInstanceOf(PDO::class, $connection);
        $connection->exec('BEGIN');
        $connection->exec("INSERT INTO steps VALUES ('uncommitted')");
        $adapter->close($this->detach($connection));
        $check = new PDO('sqlite:' . $path);
        $count = $check->query('SELECT count(*) FROM steps');
        self::assertInstanceOf(PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testPdoLeaseWithARetainedStatementIsNotReleased(): void
    {
        $path = $this->workingDirectory . '/agents/42.sqlite';
        $acquired = ['database' => ['name' => 'agents/42.sqlite', 'path' => $path, 'role' => 'origin', 'state' => 'synced', 'acquired' => true]];
        $daemon = $this->startDaemon([
            FakeDaemon::json(['path' => '/v1/databases/acquire'], $acquired, 200, ['uses' => 0]),
            FakeDaemon::json(['path' => '/v1/databases/release'], ['database' => $acquired['database']], 200, ['uses' => 0]),
        ]);
        $pool = $this->clientTo($daemon)->databases->pool(PdoSqlite::adapter());
        $retained = null;
        $pool->lease('agents/42.sqlite', static function (object $connection) use (&$retained): void {
            self::assertInstanceOf(PDO::class, $connection);
            $connection->exec('CREATE TABLE steps (body TEXT)');
            $retained = $connection->query('SELECT count(*) FROM steps');
        }, path: 'agents/42.sqlite');
        self::assertInstanceOf(PDOStatement::class, $retained);
        try {
            $pool->close();
            self::fail('the pool closed');
        } catch (LeaseCleanupError $error) {
            self::assertSame(LeaseCleanupError::STEP_CLOSE, $error->step);
            self::assertSame(['step' => 'close', 'name' => 'agents/42.sqlite', 'path' => $path, 'acquired' => true], $error->details);
            self::assertInstanceOf(LitebackupError::class, $error->getPrevious());
        }
        self::assertSame(['/v1/databases/acquire'], array_column($daemon->requests(), 'path'));
        // Once the statement goes, the release goes through.
        $retained = null;
        $pool->release('agents/42.sqlite');
        self::assertSame(['/v1/databases/acquire', '/v1/databases/release'], array_column($daemon->requests(), 'path'));
    }

    #[RequiresPhpExtension('sqlite3')]
    public function testSqlite3AdapterOpensExistingFilesAndRollsBack(): void
    {
        $adapter = Sqlite3::adapter();
        $path = $this->workingDirectory . '/app.sqlite';
        try {
            $adapter->open($path);
            self::fail('a missing file was opened');
        } catch (LitebackupError $error) {
            self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode);
        }
        $setup = new SqliteDatabase($path);
        $setup->exec('CREATE TABLE steps (body TEXT)');
        $setup->close();
        $database = $adapter->open($path);
        self::assertInstanceOf(SqliteDatabase::class, $database);
        self::assertSame($path, Sqlite3::databasePath($database));
        $database->exec('BEGIN');
        $database->exec("INSERT INTO steps VALUES ('uncommitted')");
        $adapter->close($database);
        $check = new SqliteDatabase($path);
        self::assertSame(0, $check->querySingle('SELECT count(*) FROM steps'));
        $check->close();
        // A close with no transaction open passes too.
        $adapter->close($adapter->open($path));
    }

    public function testComposerNamesTheProviderLaravelLoads(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        /** @var array{extra?: array{laravel?: array{providers?: list<string>}}} $composer */
        self::assertSame([LitebackupServiceProvider::class], $composer['extra']['laravel']['providers'] ?? null);
    }

    /** Hands an object over without keeping a reference, as the pool does. */
    private function detach(object &$handle): object
    {
        $detached = $handle;
        $handle = new \stdClass();
        return $detached;
    }
}
