<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * Opens and closes the handle a Pool passes to its calls, such as a PDO
 * connection. Integrations\PdoSqlite and Integrations\Sqlite3 are adapters.
 *
 * open must open an existing file and never create one: a missing file is
 * an offloaded copy the pool brings back, never an empty new database;
 * throw LitebackupError with code not_found for it. close must roll back a
 * transaction a call left unfinished, never commit it, and throw when a
 * connection may still be open: the pool releases a leased database only
 * after close succeeds. The pool hands close its only reference to the
 * handle, so an adapter can tell, through a WeakReference, whether anything
 * else still holds it.
 */
interface Adapter
{
    public function open(string $path): object;

    public function close(object $handle): void;
}
