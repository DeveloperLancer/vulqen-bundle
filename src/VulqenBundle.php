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
use Vulqen\SymfonyBundle\Agent\AgentCommand;
use Vulqen\SymfonyBundle\Agent\AgentCycle;
use Vulqen\SymfonyBundle\Agent\HttpStatusFetch;
use Vulqen\SymfonyBundle\Agent\NullStatusFetch;
use Vulqen\SymfonyBundle\Agent\ProcHostReader;
use Vulqen\SymfonyBundle\DependencyInjection\HttpClientPass;
use Vulqen\SymfonyBundle\DependencyInjection\MessengerMiddlewarePass;
use Vulqen\SymfonyBundle\Doctrine\VulqenMiddleware;
use Vulqen\SymfonyBundle\EventListener\ConsoleListener;
use Vulqen\SymfonyBundle\EventListener\HttpListener;
use Vulqen\SymfonyBundle\Messenger\TraceMiddleware;
use Vulqen\SymfonyBundle\Opcache\OpcacheEndpoint;
use Vulqen\SymfonyBundle\Scheduler\SchedulerCheckInListener;

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
        $children->scalarNode('fpm_status_url')->defaultNull();
        $children->scalarNode('opcache_url')->defaultNull();
        $children->scalarNode('disk_path')->defaultNull();
        $children->scalarNode('fpm_pool')->defaultValue('www');
        $excluded = $children->arrayNode('excluded_routes');
        $excluded->defaultValue([]);
        $excluded->scalarPrototype();
    }

    /**
     * @param array{enabled: bool, dsn: ?string, environment: string, release: ?string, server_name: ?string, fpm_status_url: ?string, opcache_url: ?string, disk_path: ?string, fpm_pool: string, excluded_routes: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('vulqen.default_environment', 'prod');

        if (!$config['enabled']) {
            // The processed config still holds the env placeholders. Symfony then
            // requires every such placeholder to appear in the dumped container.
            // Resolving them here marks the variables used without registering listeners.
            $builder->resolveEnvPlaceholders($config);

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

        $excluded = $config['excluded_routes'];
        $excluded[] = '_vulqen_opcache';
        $services->set('vulqen.http_listener', HttpListener::class)
            ->args([service('vulqen.client'), $excluded])
            ->tag('kernel.event_subscriber');

        $services->set('vulqen.opcache_endpoint', OpcacheEndpoint::class)
            ->args([$config['dsn']])
            ->tag('kernel.event_subscriber');

        if (interface_exists(\Symfony\Contracts\HttpClient\HttpClientInterface::class)) {
            $services->set('vulqen.status_fetch', HttpStatusFetch::class)
                ->args([service('http_client')->nullOnInvalid(), $config['dsn']]);
        } else {
            $services->set('vulqen.status_fetch', NullStatusFetch::class);
        }

        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $disk = $config['disk_path'];
            $services->set('vulqen.host_readings', ProcHostReader::class);
            $services->set('vulqen.agent_cycle', AgentCycle::class)
                ->args([
                    service('vulqen.client'),
                    service('vulqen.host_readings'),
                    service('vulqen.status_fetch'),
                    is_string($config['fpm_status_url']) ? $config['fpm_status_url'] : '',
                    is_string($config['opcache_url']) ? $config['opcache_url'] : '',
                    is_string($disk) && $disk !== '' ? $disk : param('kernel.project_dir'),
                    $config['fpm_pool'],
                ]);
            $services->set('vulqen.agent_command', AgentCommand::class)
                ->args([
                    service('vulqen.agent_cycle'),
                    is_string($config['server_name']) ? $config['server_name'] : '',
                ])
                ->tag('console.command');
        }

        if (class_exists(\Symfony\Component\Scheduler\Event\PreRunEvent::class)) {
            $services->set('vulqen.scheduler_checkin', SchedulerCheckInListener::class)
                ->args([service('vulqen.client')])
                ->tag('kernel.event_subscriber');
        }

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
