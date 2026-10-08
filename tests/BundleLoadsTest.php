<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Vulqen\SymfonyBundle\VulqenBundle;

final class BundleLoadsTest extends TestCase
{
    public function testBundleJestZarejestrowany(): void
    {
        $kernel = new class('test', true) extends Kernel {
            public function registerBundles(): iterable
            {
                return [
                    new FrameworkBundle(),
                    new VulqenBundle(),
                ];
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(static function (ContainerBuilder $container): void {
                    $container->loadFromExtension('framework', [
                        'secret' => 'test',
                        'http_method_override' => false,
                        'handle_all_throwables' => true,
                        'php_errors' => ['log' => true],
                    ]);
                });
            }

            public function getCacheDir(): string
            {
                return sys_get_temp_dir().'/vulqen-bundle-cache';
            }

            public function getLogDir(): string
            {
                return sys_get_temp_dir().'/vulqen-bundle-log';
            }
        };

        $kernel->boot();

        self::assertInstanceOf(VulqenBundle::class, $kernel->getBundle('VulqenBundle'));

        $kernel->shutdown();
    }
}
