<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\App;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: mixed}> */
    public static array $calls = [];

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
        ];

        return new MockResponse('ok');
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Ten test nie czyta strumienia.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
