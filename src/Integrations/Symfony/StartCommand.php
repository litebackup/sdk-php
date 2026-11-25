<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Symfony;

use Litebackup\Integrations\ConfigurationError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/console litebackup:start: registers the backups of the configured
 * connections and prints each rule's path. Run it on every deploy; the same
 * settings register again safely, and changed settings fail with
 * rule_exists.
 */
#[AsCommand(name: 'litebackup:start', description: 'Registers continuous backups of the SQLite databases in litebackup.connections')]
final class StartCommand extends Command
{
    public function __construct(private readonly DoctrineBackups $backups)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->backups->enabled) {
            $output->writeln('litebackup.enabled is false; no backups were registered');
            return Command::SUCCESS;
        }
        try {
            $rules = $this->backups->register();
        } catch (ConfigurationError $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($rules === []) {
            $output->writeln('litebackup.connections names no connections; no backups were registered');
        }
        foreach ($rules as $rule) {
            $output->writeln($rule->path);
        }
        return Command::SUCCESS;
    }
}
