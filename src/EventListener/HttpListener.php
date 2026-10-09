<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Vulqen\Sdk\Client;
use Vulqen\Sdk\Model\Transaction;

/**
 * Transakcja HTTP po nazwie trasy (31). Wysyłka w kernel.terminate, czyli po fastcgi_finish_request na FPM.
 */
final class HttpListener implements EventSubscriberInterface
{
    public const UNMATCHED = 'http.unmatched';

    /** Transakcja starsza niż godzina oznacza REQUEST_TIME_FLOAT z innego żądania procesu długo żyjącego. */
    private const MAX_REQUEST_AGE = 3600.0;

    /** @var array<string, true> */
    private readonly array $excludedRoutes;

    /** @var array<string, int>|null */
    private ?array $usageAtStart = null;

    private ?int $lastCaptured = null;

    /**
     * @param list<string> $excludedRoutes
     */
    public function __construct(private readonly Client $client, array $excludedRoutes)
    {
        $this->excludedRoutes = array_fill_keys($excludedRoutes, true);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onRequest', 4096], ['onRouted', 31]],
            KernelEvents::EXCEPTION => ['onException', 128],
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->client->isEnabled()) {
            return;
        }

        $now = microtime(true);
        $startedAt = $event->getRequest()->server->get('REQUEST_TIME_FLOAT');
        $startedAt = is_numeric($startedAt) && $now - (float) $startedAt < self::MAX_REQUEST_AGE ? min($now, (float) $startedAt) : $now;

        $this->usageAtStart = getrusage() ?: null;
        $this->lastCaptured = null;
        $this->client->startTransaction(self::UNMATCHED, Transaction::OP_HTTP, $startedAt);
    }

    /**
     * Zaraz po RouterListener (32): wykluczona trasa porzuca transakcję, zanim kontroler zrobi SQL.
     */
    public function onRouted(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->client->transaction() === null) {
            return;
        }
        if ($this->excluded($event->getRequest()->attributes->get('_route'))) {
            $this->client->discardTransaction();
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }
        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500) {
            return;
        }
        if ($this->lastCaptured === spl_object_id($throwable)) {
            return;
        }
        $this->lastCaptured = spl_object_id($throwable);
        $this->client->captureException($throwable);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $transaction = $this->client->transaction();
        if ($transaction === null) {
            $this->client->flush();

            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ($this->excluded($route)) {
            $this->client->discardTransaction();
            $this->client->flush();

            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $transaction->setName(is_string($route) && $route !== '' ? $route : self::UNMATCHED);
        $transaction->setHttpStatus($status);
        if ($status >= 500) {
            $transaction->markError();
        }
        $transaction->setResources(memory_get_peak_usage(true), $this->cpuMs());

        $this->client->finishTransaction();
        $this->client->flush();
    }

    /**
     * Trasy z excluded_routes i wewnętrzne trasy Symfony (_wdt, _profiler).
     */
    private function excluded(mixed $route): bool
    {
        return is_string($route) && ($route === '' || $route[0] === '_' || isset($this->excludedRoutes[$route]));
    }

    private function cpuMs(): ?float
    {
        $start = $this->usageAtStart;
        $end = getrusage() ?: null;
        if ($start === null || $end === null) {
            return null;
        }

        $micro = static fn (array $usage): int => ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];

        return max(0, $micro($end) - $micro($start)) / 1000;
    }
}
