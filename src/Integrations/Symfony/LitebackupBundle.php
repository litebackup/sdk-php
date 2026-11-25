<?php

declare(strict_types=1);

namespace Litebackup\Integrations\Symfony;

use Litebackup\Generated\Durability;
use Litebackup\Integrations\BackupRegistration;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Registers continuous backups of a Symfony app's SQLite databases, the
 * Doctrine DBAL connections litebackup.connections names:
 *
 *   litebackup:
 *       connections:
 *           default: ~
 *           queue: { name: app/queue.sqlite }
 *
 * Each connection's options are backups->start()'s: name, prefix,
 * maximum_local_databases, offload_durability, and
 * offload_rollback_journal. The client takes api_key, session, socket_path,
 * and binary_path from litebackup too. enabled is false in the test
 * environment and true otherwise.
 *
 * Registration happens in bin/console litebackup:start, to run on every
 * deploy, and once in each messenger:consume worker before it consumes. The
 * bundle does nothing at boot: HTTP requests, cache:clear,
 * doctrine:migrations:migrate, and other commands never call the daemon.
 */
final class LitebackupBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $settings = $root->children();
        $settings->booleanNode('enabled')->defaultNull();
        $connection = $settings->arrayNode('connections')
            ->useAttributeAsKey('connection')
            ->arrayPrototype()
            ->children();
        $connection->scalarNode('name');
        $connection->scalarNode('prefix');
        $connection->integerNode('maximum_local_databases');
        $connection->enumNode('offload_durability')->values(Durability::ALL);
        $connection->booleanNode('offload_rollback_journal');
        foreach (BackupRegistration::CLIENT_KEYS as $clientKey) {
            $settings->scalarNode($clientKey)->defaultNull();
        }
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $enabled = $config['enabled'] ?? ($builder->getParameter('kernel.environment') !== 'test');
        $clientSettings = array_intersect_key($config, array_flip(BackupRegistration::CLIENT_KEYS));
        $services = $container->services();
        $services->set('litebackup.doctrine_backups', DoctrineBackups::class)
            ->args([
                service('doctrine'),
                $config['connections'] ?? [],
                $clientSettings,
                param('kernel.environment'),
                $enabled,
            ]);
        $services->alias(DoctrineBackups::class, 'litebackup.doctrine_backups');
        $services->set('litebackup.start_command', StartCommand::class)
            ->args([service('litebackup.doctrine_backups')])
            ->tag('console.command');
        $services->set('litebackup.worker_backups', WorkerBackups::class)
            ->args([service('litebackup.doctrine_backups')])
            ->tag('kernel.event_listener', ['event' => ConsoleEvents::COMMAND, 'method' => 'registerBeforeConsuming']);
    }
}
