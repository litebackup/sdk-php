<?php

declare(strict_types=1);

namespace Litebackup\Tests\Frameworks\Symfony;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Litebackup\Integrations\Symfony\LitebackupBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A small Symfony app in a directory of its own: FrameworkBundle with an
 * in-memory Messenger transport and a /ping route, DoctrineBundle with
 * the connections a test names, and LitebackupBundle with its settings.
 */
final class BackupsKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $litebackupSettings the litebackup configuration
     * @param array<string, array<string, mixed>> $connections the doctrine.dbal.connections configuration
     */
    public function __construct(
        private readonly string $projectDirectory,
        string $environment,
        private readonly array $litebackupSettings,
        private readonly array $connections,
    ) {
        parent::__construct($environment, false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new LitebackupBundle();
    }

    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }

    public function getCacheDir(): string
    {
        return $this->projectDirectory . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->projectDirectory . '/var/log';
    }

    /** The /ping route's answer. */
    public function ping(): Response
    {
        return new Response('pong');
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'litebackup-test',
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'messenger' => ['transports' => ['memory' => 'in-memory://']],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['default_connection' => array_key_first($this->connections), 'connections' => $this->connections],
        ]);
        $container->extension('litebackup', $this->litebackupSettings);
        // The default logger writes to standard error, which a test in its own process may not.
        $container->services()->set('logger', NullLogger::class);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller([$this, 'ping']);
    }
}
