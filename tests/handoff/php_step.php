<?php

declare(strict_types=1);

/*
 * The PHP step of the cross-language handoff test, which
 * sdk/python/tests/test_integration.py runs through scripts/php-in-docker
 * when LITEBACKUP_PHP names a command.
 *
 * Usage: php php_step.php NAME PATH EXPECTED-ROWS ADDED-ROWS
 *
 * It leases NAME at PATH in the session LITEBACKUP_SESSION, checks that the
 * database holds EXPECTED-ROWS rows, adds ADDED-ROWS, and closes the pool,
 * which releases the database. Its daemon runs in this container, so the
 * step shuts the session down before it exits: the next step's machine
 * acquires the database from the server.
 */

use Litebackup\Client;
use Litebackup\Integrations\PdoSqlite;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $name, $path, $expected, $added] = $argv;
$client = new Client();
try {
    $pool = $client->databases->pool(PdoSqlite::adapter());
    $pool->lease($name, static function (object $connection) use ($expected, $added): void {
        if (!$connection instanceof PDO) {
            throw new RuntimeException('the PDO adapter leased ' . get_debug_type($connection));
        }
        $count = $connection->query('SELECT count(*) FROM steps');
        $stepCount = $count === false ? -1 : (int) $count->fetchColumn();
        $count = null;
        if ($stepCount !== (int) $expected) {
            throw new RuntimeException('found ' . $stepCount . ' rows, want ' . $expected);
        }
        $connection->beginTransaction();
        $insert = $connection->prepare('INSERT INTO steps (body) VALUES (?)');
        for ($index = 0; $index < (int) $added; $index++) {
            $insert->execute(['php ' . $index]);
        }
        $insert = null;
        $connection->commit();
    }, path: $path, durability: 'server');
    $pool->close();
    echo "php handoff ok\n";
} finally {
    try {
        $shutdown = $client->session->shutdown(durability: 'server');
        if ($shutdown->failed !== []) {
            fwrite(STDERR, 'the session kept ' . implode(', ', array_map(static fn($failure): string => $failure->name, $shutdown->failed)) . "\n");
        }
    } finally {
        $client->close();
    }
}
