<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TracingDriver extends AbstractDriverMiddleware
{
    public function __construct(private readonly Driver $driver, private readonly QueryTracer $tracer)
    {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        $connection = parent::connect($params);
        $this->tracer->connected($params, $this->driver);

        return new TracingConnection($connection, $this->tracer);
    }
}
