<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vulqen\SymfonyBundle\HttpClient\TracingHttpClient;

/**
 * Dekoruje http_client tylko wtedy, gdy serwis istnieje. loadExtension jest za wcześnie:
 * FrameworkBundle rejestruje klienta później.
 */
final class HttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('vulqen.client') || !$container->has('http_client')) {
            return;
        }

        $container->register('vulqen.http_client', TracingHttpClient::class)
            ->setDecoratedService('http_client')
            ->setArguments([new Reference('.inner'), new Reference('vulqen.client')]);
    }
}
