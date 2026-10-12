<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Opcache;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vulqen\Sdk\Dsn;

/**
 * GET /_vulqen/opcache, authorized by the ingest key.
 * The route name starts with "_" so the HTTP listener drops the transaction.
 */
final class OpcacheEndpoint implements EventSubscriberInterface
{
    public function __construct(private readonly ?string $dsn)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 33]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getPathInfo() !== '/_vulqen/opcache') {
            return;
        }

        $event->getRequest()->attributes->set('_route', '_vulqen_opcache');
        $event->setResponse($this->response($event->getRequest()));
    }

    private function response(Request $request): JsonResponse
    {
        $key = $this->key();
        $given = (string) $request->headers->get('X-Vulqen-Key', '');
        if ($key === '' || $given === '' || !hash_equals($key, $given)) {
            return new JsonResponse(['error' => 'ingest.unknown_key'], 401);
        }

        return new JsonResponse(PhpOpcacheStatus::read());
    }

    private function key(): string
    {
        try {
            $parsed = Dsn::parse($this->dsn);

            return $parsed === null ? '' : $parsed->key;
        } catch (\Throwable) {
            return '';
        }
    }
}
