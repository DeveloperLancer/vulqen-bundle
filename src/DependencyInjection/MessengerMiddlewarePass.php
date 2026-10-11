<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dokłada middleware na początek istniejących magistrali, przed send_message.
 * Aplikacja bez Messengera nie dostaje tej usługi.
 */
final class MessengerMiddlewarePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('vulqen.trace_middleware')) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $parameter = $busId.'.middleware';
            if (!$container->hasParameter($parameter)) {
                continue;
            }
            $middleware = $container->getParameter($parameter);
            if (!is_array($middleware)) {
                continue;
            }
            foreach ($middleware as $item) {
                if (is_array($item) && ($item['id'] ?? null) === 'vulqen.trace_middleware') {
                    continue 2;
                }
            }
            array_unshift($middleware, ['id' => 'vulqen.trace_middleware']);
            $container->setParameter($parameter, $middleware);
        }
    }
}
