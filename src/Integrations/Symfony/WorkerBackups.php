<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Symfony;

use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Registers the configured backups once in each messenger:consume worker,
 * before it consumes its first message. A worker whose registration fails
 * stops with the ConfigurationError. Every other command is left alone.
 */
final class WorkerBackups
{
    /** The command that starts a Messenger worker. */
    public const CONSUME_COMMAND = 'messenger:consume';

    /** Whether this PHP process registered its backups already. */
    private static bool $registered = false;

    public function __construct(private readonly DoctrineBackups $backups) {}

    public function registerBeforeConsuming(ConsoleCommandEvent $event): void
    {
        if (self::$registered || !$this->backups->enabled || $event->getCommand()?->getName() !== self::CONSUME_COMMAND) {
            return;
        }
        $this->backups->register();
        self::$registered = true;
    }
}
