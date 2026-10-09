<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Vulqen\SymfonyBundle\VulqenBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const DSN = 'https://vq_live_test@ingest.example.test/0190a5b2-7c3d-7e4f-8a1b-2c3d4e5f6a7b';

    /**
     * @param array<string, mixed> $vulqen
     */
    public function __construct(private readonly array $vulqen, private readonly bool $doctrine = true)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->doctrine) {
            yield new DoctrineBundle();
        }
        yield new VulqenBundle();
    }

    public function getCacheDir(): string
    {
        return self::baseDir().'/cache/'.md5(serialize([$this->vulqen, $this->doctrine]));
    }

    public function getLogDir(): string
    {
        return self::baseDir().'/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir().'/vulqen-bundle-tests-'.getmypid();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        if ($this->doctrine) {
            $container->extension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ]);
        }
        $container->extension('vulqen', $this->vulqen);

        $services = $container->services();
        $services->set(TestController::class)
            ->public()
            ->args([$this->doctrine ? service('doctrine.dbal.default_connection') : null]);
        $services->set('vulqen.transport', CapturingTransport::class)->public();
        $services->set('logger', NullLogger::class);
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('app_order_show', '/orders/{id}')
            ->controller([TestController::class, 'order'])
            ->requirements(['id' => '\d+']);
        $routes->add('app_fail', '/fail')->controller([TestController::class, 'fail']);
        $routes->add('app_leak', '/leak')->controller([TestController::class, 'leak']);
        $routes->add('app_health', '/health')->controller([TestController::class, 'health']);
    }
}
