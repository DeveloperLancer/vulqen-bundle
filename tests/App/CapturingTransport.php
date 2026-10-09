<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\App;

use Vulqen\Sdk\Dsn;
use Vulqen\Sdk\Transport\Transport;
use Vulqen\Sdk\Transport\TransportResult;

final class CapturingTransport implements Transport
{
    /** @var list<string> */
    public array $sent = [];

    public bool $throw = false;

    public function send(Dsn $dsn, string $json): TransportResult
    {
        $this->sent[] = $json;
        if ($this->throw) {
            throw new \RuntimeException('transport padł');
        }

        return new TransportResult(202);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->sent as $json) {
            $envelope = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            \assert(is_array($envelope) && is_array($envelope['items'] ?? null));
            foreach ($envelope['items'] as $item) {
                \assert(is_array($item));
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsOfType(string $type): array
    {
        return array_values(array_filter($this->items(), static fn (array $item): bool => ($item['type'] ?? null) === $type));
    }
}
