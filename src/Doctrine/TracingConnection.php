<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * exec zwraca int, bo DBAL 3 deklaruje int, a DBAL 4 int|string i węższy typ pasuje do obu.
 */
final class TracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryTracer $tracer)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $this->tracer, $sql);
    }

    public function query(string $sql): Result
    {
        $span = $this->tracer->start($sql);
        try {
            return parent::query($sql);
        } finally {
            $this->tracer->finish($span);
        }
    }

    public function exec(string $sql): int
    {
        $span = $this->tracer->start($sql);
        try {
            return (int) parent::exec($sql);
        } finally {
            $this->tracer->finish($span);
        }
    }
}
