<?php

declare(strict_types=1);

namespace Litebackup\Integrations;

use Exception;
use Litebackup\Adapter;
use Litebackup\LitebackupError;
use SQLite3 as SqliteDatabase;

/**
 * The SQLite3 extension: the file a database has open, and a pool adapter.
 *
 *     $pool = $litebackup->databases->pool(Litebackup\Integrations\Sqlite3::adapter());
 *
 * The adapter opens existing files with SQLITE3_OPEN_READWRITE, which never
 * creates one; a missing file throws LitebackupError with code not_found.
 * Closing rolls back a transaction work left open, never commits it, and
 * closes the database. Commit what work should keep.
 */
final class Sqlite3 implements Adapter
{
    private function __construct(private readonly ?string $encryptionKey) {}

    /** A pool adapter; encryptionKey goes to SQLite3::__construct for builds that encrypt. */
    public static function adapter(?string $encryptionKey = null): self
    {
        return new self($encryptionKey);
    }

    /**
     * The absolute path of the file a database has open, or of an attached
     * database; throws invalid_argument for in-memory and temporary ones.
     */
    public static function databasePath(SqliteDatabase $database, string $schema = 'main'): string
    {
        $listing = $database->query('PRAGMA database_list');
        if ($listing === false) {
            throw LitebackupError::invalidArgument('PRAGMA database_list failed on this database');
        }
        $entries = [];
        while (($entry = $listing->fetchArray(SQLITE3_ASSOC)) !== false) {
            $entries[] = $entry;
        }
        $listing->finalize();
        return SqliteFile::fileFromDatabaseList($entries, $schema);
    }

    public function open(string $path): object
    {
        SqliteFile::requireExistingFile($path);
        $database = new SqliteDatabase($path, SQLITE3_OPEN_READWRITE, $this->encryptionKey ?? '');
        $database->enableExceptions(true);
        return $database;
    }

    public function close(object $handle): void
    {
        if (!$handle instanceof SqliteDatabase) {
            throw LitebackupError::invalidArgument('the SQLite3 adapter closes SQLite3 databases, got ' . get_debug_type($handle));
        }
        try {
            $handle->exec('ROLLBACK');
        } catch (Exception $error) {
            if (!str_contains($error->getMessage(), 'no transaction is active')) {
                throw $error;
            }
        }
        if (!$handle->close()) {
            throw LitebackupError::invalidArgument('closing the SQLite3 database failed: ' . $handle->lastErrorMsg());
        }
    }
}
