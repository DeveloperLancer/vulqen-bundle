<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Vulqen\Sdk\Client;

/**
 * Middleware DBAL 3 i 4 z tagu doctrine.middleware. Każde zapytanie to span db.query.
 */
final class VulqenMiddleware implements Middleware
{
    public function __construct(private readonly Client $client)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, new QueryTracer($this->client));
    }
}
