# Litebackup for PHP

Back up, replicate, and restore SQLite databases with your Litebackup server
and bucket, from PHP, Laravel, and Symfony. PHP 8.2 or later on Linux or
macOS. The package needs only `ext-json`; the PDO adapter needs
`ext-pdo_sqlite`, the SQLite3 adapter `ext-sqlite3`, and release
verification `ext-sodium`.

Install the Composer package `litebackup/sdk` from Packagist:

```sh
composer require litebackup/sdk
export LITEBACKUP_API_KEY='JOIN_TOKEN@your-instance.litebackup.cloud'
```

`LITEBACKUP_API_KEY` is the value from **Machines → Add machine**, or an API
key followed by @ and your server address. The package carries no
`litebackup` command: install it with
`curl -fsSL https://litebackup.com/cli.sh | bash`, or set `LITEBACKUP_PATH`.
The SDK uses `LITEBACKUP_PATH`, then `litebackup` on `PATH`, then
`~/.local/bin/litebackup`.

## Back up an app's database

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Litebackup\Client;
use Litebackup\Integrations\PdoSqlite;

$db = new PDO('sqlite:data/app.sqlite');
$litebackup = new Client();
try {
    $rule = $litebackup->backups->start(PdoSqlite::databasePath($db));
    echo $rule->id, ' ', $rule->name, PHP_EOL;
} finally {
    $litebackup->close();
}
```

`backups->start()` returns the saved rule when the same path was registered
with the same settings, so call it on every start. Different settings for a
saved path raise `rule_exists`, with the saved rule in
`$error->details['rule']`.

## Laravel and Symfony

In Laravel, publish the settings with
`php artisan vendor:publish --tag=litebackup-config`, name the connections
in `config/litebackup.php`, and run `php artisan litebackup:start` at every
deploy:

```php
return [
    'connections' => ['sqlite' => []],
];
```

In Symfony, register `Litebackup\Integrations\Symfony\LitebackupBundle` in
`config/bundles.php`, name the Doctrine DBAL connections under
`litebackup.connections`, and run `bin/console litebackup:start` at every
deploy. Workers (`queue:work`, `horizon:work`, and `schedule:work` in
Laravel, `messenger:consume` in Symfony) register the backups once when they
start; HTTP requests never call the daemon. The [PHP guide](https://github.com/litebackup/sdk/blob/main/docs/sqlite-php.md) has the options.

## Move a database between machines

A pool keeps a database open between calls. A lease locks the database for
this machine, so no other machine writes it, and keeps the lock until the
pool releases it:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Litebackup\Client;
use Litebackup\Integrations\Sqlite3;

$litebackup = new Client();
$pool = $litebackup->databases->pool(Sqlite3::adapter());
try {
    $pool->lease('agents/42.sqlite', function (SQLite3 $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS steps (body TEXT)');
        $db->exec("INSERT INTO steps (body) VALUES ('tests passed')");
    }, path: 'data/agents/42.sqlite', durability: 'server');
} finally {
    $pool->close();
    $litebackup->close();
}
```

The adapters never commit: SQLite commits each statement outside a
transaction, and a transaction the work leaves open is rolled back when the
pool closes the database. Work that throws keeps the lease and never
offloads. PHP has no threads, so a pool releases an idle lease only when it
is called again or closed; the guide covers PHP-FPM and workers.

## Errors

Every failure is a `Litebackup\LitebackupError` with `errorCode`, the
message, `status`, and `details`. `Litebackup\ErrorCode` names the codes
worth matching:

```php
use Litebackup\ErrorCode;
use Litebackup\LitebackupError;

try {
    $litebackup->databases->acquire('agents/42.sqlite', path: 'data/agents/42.sqlite');
} catch (LitebackupError $error) {
    if ($error->errorCode !== ErrorCode::LOCK_UNAVAILABLE) {
        throw $error;
    }
    error_log('another machine writes agents/42.sqlite; try again later');
}
```

## Development

`scripts/test` runs PHPUnit, PHPStan, PHP-CS-Fixer, and the Laravel and
Symfony lanes in `php:8.2.36-cli`, `php:8.3.37-cli`, `php:8.4.28-cli`, and
`php:8.5.13-cli` as the calling user. `scripts/test --php 8.4.28` runs one
version, `scripts/test -- vendor/bin/phpunit tests/Unit/PoolTest.php` runs a
command in the package's directory, and `scripts/test generate` regenerates
`src/Generated` from `../client-openapi.yaml`. `scripts/test --integration`
also runs `tests/Integration/IntegrationTest.php` against
`litebackup server run --development`, with `LITEBACKUP_PATH` set to a
static `litebackup` binary. `scripts/check-package` builds the Composer
archive and installs it into a clean project without publishing anything. The [SDK reference](https://github.com/litebackup/sdk/blob/main/docs/sdk-reference.md) lists
every method, and [the contract](https://github.com/litebackup/sdk/blob/main/docs/sdk-contract.md) the behavior the
Go, TypeScript, Python, Ruby, and PHP SDKs share.
