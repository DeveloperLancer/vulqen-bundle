<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Doctrine;

use Vulqen\Sdk\Client;
use Vulqen\Sdk\Model\Span;
use Vulqen\Sdk\SqlNormalizer;

/**
 * Span db.query z tekstem po normalizacji SDK. Parametry zapytania nigdy nie trafiają do zdarzenia (D-020).
 */
final class QueryTracer
{
    public const OP = 'db.query';

    private const CACHE_SIZE = 256;

    private string $system = 'other';

    /** @var array<string, string> */
    private array $normalized = [];

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connected(array $params, object $driver): void
    {
        $name = strtolower(implode(' ', array_filter([
            is_string($params['driver'] ?? null) ? $params['driver'] : null,
            is_string($params['driverClass'] ?? null) ? $params['driverClass'] : null,
            $driver::class,
        ])));

        $this->system = match (true) {
            str_contains($name, 'mysql') => 'mysql',
            str_contains($name, 'pgsql'), str_contains($name, 'postgres') => 'postgresql',
            str_contains($name, 'sqlite') => 'sqlite',
            str_contains($name, 'sqlsrv') => 'mssql',
            str_contains($name, 'oci'), str_contains($name, 'oracle') => 'oracle',
            default => 'other',
        };
    }

    public function start(string $sql): ?Span
    {
        if ($this->client->transaction() === null) {
            return null;
        }

        $statement = $this->normalize($sql);

        return $this->client->startSpan(self::OP, $statement, [
            'db.system' => $this->system,
            'db.statement' => $statement,
        ]);
    }

    public function finish(?Span $span): void
    {
        $this->client->finishSpan($span);
    }

    private function normalize(string $sql): string
    {
        if (isset($this->normalized[$sql])) {
            return $this->normalized[$sql];
        }
        if (count($this->normalized) >= self::CACHE_SIZE) {
            $this->normalized = [];
        }

        try {
            $normalized = SqlNormalizer::normalize($sql);
        } catch (\Throwable) {
            $normalized = SqlNormalizer::UNPARSED;
        }

        return $this->normalized[$sql] = $normalized;
    }
}
