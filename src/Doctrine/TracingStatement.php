<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL 3 przyjmuje parametry w execute, DBAL 4 nie. Opcjonalny parametr pasuje do obu sygnatur.
 */
final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly QueryTracer $tracer, private readonly string $sql)
    {
        parent::__construct($statement);
    }

    public function execute(mixed $params = null): Result
    {
        $span = $this->tracer->start($this->sql);
        try {
            return $params === null ? parent::execute() : parent::execute(...[$params]);
        } finally {
            $this->tracer->finish($span);
        }
    }
}
