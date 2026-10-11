<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle;

use Doctrine\DBAL\Driver\Middleware;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Vulqen\Sdk\Client;
use Vulqen\Sdk\Options;
use Vulqen\Sdk\Transport\CurlTransport;
use Vulqen\SymfonyBundle\DependencyInjection\HttpClientPass;
use Vulqen\SymfonyBundle\DependencyInjection\MessengerMiddlewarePass;
use Vulqen\SymfonyBundle\Doctrine\VulqenMiddleware;
use Vulqen\SymfonyBundle\EventListener\ConsoleListener;
use Vulqen\SymfonyBundle\EventListener\HttpListener;
use Vulqen\SymfonyBundle\Messenger\TraceMiddleware;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Instrumentacja HTTP, wyjątków i SQL (31). enabled: false nie rejestruje żadnego słuchacza.
 * Pusty DSN rejestruje słuchacze, które wracają od razu.
 */
final class VulqenBundle extends AbstractBundle
{
    public const SDK_NAME = 'vulqen-symfony';
    public const VERSION = '0.2.0';

    protected string $extensionAlias = 'vulqen';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();
        $children = $root->children();
        $children->booleanNode('enabled')->defaultTrue();
        $children->scalarNode('dsn')->defaultNull();
        $children->scalarNode('environment')->defaultValue('prod');
        $children->scalarNode('release')->defaultNull();
        $children->scalarNode('server_name')->defaultNull();
        $excluded = $children->arrayNode('excluded_routes');
        $excluded->defaultValue([]);
        $excluded->scalarPrototype();
    }

    /**
     * @param array{enabled: bool, dsn: ?string, environment: string, release: ?string, server_name: ?string, excluded_routes: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('vulqen.default_environment', 'prod');

        if (!$config['enabled']) {
            return;
        }

        $services = $container->services();

        $services->set('vulqen.transport', CurlTransport::class);

        $services->set('vulqen.options', Options::class)
            ->args([
                $config['dsn'],
                $config['environment'],
                $config['release'],
                $config['server_name'],
                self::SDK_NAME,
                self::VERSION,
                param('kernel.project_dir'),
                param('kernel.cache_dir').'/vulqen',
                service('logger')->nullOnInvalid(),
                service('vulqen.transport'),
            ]);

        $services->set('vulqen.client', Client::class)
            ->args([service('vulqen.options')])
            ->public()
            ->tag('kernel.reset', ['method' => 'reset']);
        $services->alias(Client::class, 'vulqen.client');

        $services->set('vulqen.http_listener', HttpListener::class)
            ->args([service('vulqen.client'), $config['excluded_routes']])
            ->tag('kernel.event_subscriber');

        if (interface_exists(Middleware::class)) {
            $services->set('vulqen.doctrine_middleware', VulqenMiddleware::class)
                ->args([service('vulqen.client')])
                ->tag('doctrine.middleware');
        }

        if (class_exists(ConsoleEvents::class)) {
            $services->set('vulqen.console_listener', ConsoleListener::class)
                ->args([service('vulqen.client')])
                ->tag('kernel.event_subscriber');
        }

        if (interface_exists(MiddlewareInterface::class)) {
            $services->set('vulqen.trace_middleware', TraceMiddleware::class)
                ->args([service('vulqen.client')]);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new HttpClientPass());
        $container->addCompilerPass(new MessengerMiddlewarePass(), priority: 10);
    }
}
