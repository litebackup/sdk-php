<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Laravel;

use Illuminate\Console\Command;
use Litebackup\Integrations\ConfigurationError;

/**
 * php artisan litebackup:start: registers the configured connections'
 * databases as continuous backups. Run it at every deploy; a second run
 * with the same settings changes nothing.
 */
final class StartCommand extends Command
{
    /** @var string */
    protected $signature = 'litebackup:start';

    /** @var string */
    protected $description = "Register the configured SQLite databases as continuous backups with this machine's litebackup daemon";

    public function handle(DatabaseBackups $backups): int
    {
        try {
            $rules = $backups->register();
        } catch (ConfigurationError $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        foreach ($rules as $rule) {
            $this->line('Backing up ' . $rule->path);
        }
        if ($rules === []) {
            $this->line('No connections are configured in config/litebackup.php');
        }
        return self::SUCCESS;
    }
}
