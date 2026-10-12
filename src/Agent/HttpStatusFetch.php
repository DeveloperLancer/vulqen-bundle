<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vulqen\Sdk\Dsn;

final class HttpStatusFetch implements StatusFetch
{
    public function __construct(
        private readonly ?HttpClientInterface $http,
        private readonly ?string $dsn,
    ) {
    }

    public function get(string $url): ?string
    {
        if ($this->http === null || $url === '') {
            return null;
        }

        try {
            $headers = [];
            $parsed = Dsn::parse($this->dsn);
            $key = $parsed === null ? '' : $parsed->key;
            if ($key !== '') {
                $headers['X-Vulqen-Key'] = $key;
            }
            $response = $this->http->request('GET', $url, [
                'timeout' => 2,
                'headers' => $headers,
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            return $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }
}
