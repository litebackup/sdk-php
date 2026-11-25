<?php

declare(strict_types=1);

namespace Litebackup\Integrations;

use Litebackup\Adapter;
use Litebackup\ErrorCode;
use Litebackup\LitebackupError;
use PDO;
use PDOException;
use WeakReference;

/**
 * PDO's SQLite driver: the file a connection has open, and a pool adapter.
 *
 *     $pool = $litebackup->databases->pool(Litebackup\Integrations\PdoSqlite::adapter());
 *
 * The adapter opens existing files read-write (SQLITE_OPEN_READWRITE) and never creates one; a
 * missing file throws LitebackupError with code not_found. Closing rolls
 * back a transaction work left open, never commits it, and then checks that
 * nothing else holds the connection: PDO has no close(), so a PDOStatement or
 * a variable that still holds it keeps the file open, and the close fails,
 * so the pool does not release the lease. Commit what work should keep, and
 * let its statements go when it returns.
 */
final class PdoSqlite implements Adapter
{
    /**
     * @param array<int, mixed> $options PDO attributes, such as PDO::ATTR_DEFAULT_FETCH_MODE
     */
    private function __construct(private readonly array $options) {}

    /**
     * A pool adapter; options are PDO attributes for each connection.
     *
     * @param array<int, mixed> $options
     */
    public static function adapter(array $options = []): self
    {
        if (($options[PDO::ATTR_PERSISTENT] ?? false) !== false) {
            throw LitebackupError::invalidArgument(
                'a pool cannot use persistent PDO connections: closing one leaves the file open, so it is never released',
            );
        }
        return new self($options);
    }

    /**
     * The absolute path of the file a connection has open, or of an
     * attached database. Reads PRAGMA database_list, which starts no
     * transaction; throws invalid_argument for in-memory and temporary
     * databases.
     */
    public static function databasePath(PDO $connection, string $schema = 'main'): string
    {
        $listing = $connection->query('PRAGMA database_list');
        if ($listing === false) {
            throw LitebackupError::invalidArgument('PRAGMA database_list failed on this connection');
        }
        return SqliteFile::fileFromDatabaseList($listing->fetchAll(PDO::FETCH_ASSOC), $schema);
    }

    public function open(string $path): object
    {
        SqliteFile::requireExistingFile($path);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + $this->options;
        $options[PDO::ATTR_PERSISTENT] = false;
        $options[self::driverConstant('ATTR_OPEN_FLAGS')] = self::driverConstant('OPEN_READWRITE');
        return new PDO('sqlite:' . $path, null, null, $options);
    }

    /**
     * A constant of PDO's SQLite driver: Pdo\Sqlite's from PHP 8.4, where
     * PHP 8.5 deprecates the PDO::SQLITE_ spellings, and PDO's before.
     */
    private static function driverConstant(string $name): int
    {
        $driverClass = 'Pdo\\Sqlite';
        $value = class_exists($driverClass) ? constant($driverClass . '::' . $name) : constant(PDO::class . '::SQLITE_' . $name);
        if (!is_int($value)) {
            throw new \LogicException('the SQLite driver constant ' . $name . ' is not a number');
        }
        return $value;
    }

    public function close(object $handle): void
    {
        if (!$handle instanceof PDO) {
            throw LitebackupError::invalidArgument('the PDO adapter closes PDO connections, got ' . get_debug_type($handle));
        }
        $path = self::databasePath($handle);
        // inTransaction() misses a transaction work began with a raw BEGIN,
        // so the rollback is always sent and its no-transaction error ignored.
        try {
            $handle->exec('ROLLBACK');
        } catch (PDOException $error) {
            if (!str_contains($error->getMessage(), 'no transaction is active')) {
                throw $error;
            }
            // Its trace holds the connection, as the arguments of this call.
            unset($error);
        }
        $connection = WeakReference::create($handle);
        unset($handle);
        if ($connection->get() !== null) {
            throw new LitebackupError(
                ErrorCode::STILL_OPEN,
                'the PDO connection to ' . $path . ' is still held, by a statement or a variable, so the file '
                . 'stays open; let every PDOStatement and copy of the connection go before the pool closes it',
            );
        }
    }
}
