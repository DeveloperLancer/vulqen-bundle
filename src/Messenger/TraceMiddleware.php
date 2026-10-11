<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Vulqen\Sdk\Client;
use Vulqen\Sdk\Model\Span;

/**
 * Wysyłka dokłada span queue.publish i stempel traceparent.
 * Odbiór otwiera queue.process o tym samym trace_id. Span publikacji kończy się
 * przed next(), żeby odbiór synchroniczny mógł wstrzymać transakcję rodzica.
 */
final class TraceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(VulqenTraceStamp::class);
        if ($stamp instanceof VulqenTraceStamp) {
            return $this->receive($envelope, $stack, $stamp);
        }

        return $this->publish($envelope, $stack);
    }

    private function publish(Envelope $envelope, StackInterface $stack): Envelope
    {
        $transaction = $this->client->transaction();
        $span = $transaction === null ? null : $this->client->startSpan('queue.publish', $envelope->getMessage()::class);
        $traceparent = self::traceparent($transaction?->traceparent(), $span);
        if ($span !== null) {
            $this->client->finishSpan($span);
        }
        if ($traceparent !== null) {
            $envelope = $envelope->with(new VulqenTraceStamp($traceparent));
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function receive(Envelope $envelope, StackInterface $stack, VulqenTraceStamp $stamp): Envelope
    {
        $this->client->startTransaction(self::messageName($envelope), 'queue.process', null, $stamp->traceparent);
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $error) {
            $this->client->captureException($error);

            throw $error;
        } finally {
            $this->client->finishTransaction();
            $this->client->flush();
        }
    }

    private static function messageName(Envelope $envelope): string
    {
        $name = preg_replace('/@anonymous.*$/', '', $envelope->getMessage()::class) ?? '';

        return $name !== '' ? $name : 'class@anonymous';
    }

    private static function traceparent(?string $transactionHeader, ?Span $span): ?string
    {
        if ($transactionHeader === null) {
            return null;
        }
        if ($span === null) {
            return $transactionHeader;
        }

        return substr($transactionHeader, 0, 36).$span->spanId.substr($transactionHeader, 52);
    }
}
