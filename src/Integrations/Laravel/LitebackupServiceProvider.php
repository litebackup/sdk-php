<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Laravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Registers continuous backups of a Laravel app's SQLite databases, found
 * through package discovery. Name the connections in config/litebackup.php
 * and run php artisan litebackup:start at deploy. queue:work, horizon:work
 * (each Horizon child), and schedule:work register once when they start,
 * and Octane once per worker boot; queue:listen runs queue:work for each
 * job, so it registers in each job's process. HTTP requests, migrations,
 * and other commands never call the daemon, and the provider never shuts
 * the daemon down.
 */
final class LitebackupServiceProvider extends ServiceProvider
{
    /** Octane's event, named as a string because Octane is optional. */
    public const OCTANE_WORKER_STARTING = 'Laravel\Octane\Events\WorkerStarting';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3) . '/config/litebackup.php', 'litebackup');
        $this->app->singleton(DatabaseBackups::class);
    }

    public function boot(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(self::OCTANE_WORKER_STARTING, function (): void {
            $this->app->make(DatabaseBackups::class)->registerOnce();
        });
        if (!$this->app->runningInConsole()) {
            return;
        }
        $this->publishes([dirname(__DIR__, 3) . '/config/litebackup.php' => $this->app->configPath('litebackup.php')], 'litebackup-config');
        $this->commands([StartCommand::class]);
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, DatabaseBackups::WORKER_COMMANDS, true)) {
                $this->app->make(DatabaseBackups::class)->registerOnce();
            }
        });
    }
}
