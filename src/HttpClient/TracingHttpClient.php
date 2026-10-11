<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\HttpClient;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Vulqen\Sdk\Client;
use Vulqen\Sdk\Model\Span;

/**
 * Dokleja traceparent i span http.client. Host z DSN ingestu nie dostaje ani nagłówka, ani spana (R-011).
 */
final class TracingHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $client, private readonly Client $vulqen)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = parse_url($url, PHP_URL_HOST);
        $ingest = $this->vulqen->ingestHost();
        if (is_string($host) && is_string($ingest) && strcasecmp($host, $ingest) === 0) {
            return $this->client->request($method, $url, $options);
        }

        $span = $this->vulqen->startSpan('http.client', $method.' '.$url);
        $header = self::header($this->vulqen->traceparent(), $span);
        if ($header !== null) {
            $options['headers'] = self::withHeader($options['headers'] ?? [], $header);
        }

        try {
            return $this->client->request($method, $url, $options);
        } finally {
            $this->vulqen->finishSpan($span);
        }
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<array-key, mixed>
     */
    private static function withHeader(array $headers, string $traceparent): array
    {
        if (array_is_list($headers)) {
            $headers[] = 'traceparent: '.$traceparent;

            return $headers;
        }
        $headers['traceparent'] = $traceparent;

        return $headers;
    }

    private static function header(?string $transactionHeader, ?Span $span): ?string
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
