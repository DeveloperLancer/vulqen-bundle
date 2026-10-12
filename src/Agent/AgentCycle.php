<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

use Vulqen\Sdk\Client;

/**
 * One pass of host, FPM and OPcache metrics. An empty FPM URL skips only FPM.
 */
final class AgentCycle
{
    public function __construct(
        private readonly Client $client,
        private readonly HostReadings $hosts,
        private readonly StatusFetch $status,
        private readonly string $fpmStatusUrl,
        private readonly string $opcacheUrl,
        private readonly string $diskPath,
        private readonly string $pool,
    ) {
    }

    public function capture(): void
    {
        foreach ($this->metrics() as $metric) {
            $this->client->captureMetric($metric['name'], $metric['kind'], $metric['value'], $metric['attrs']);
        }
        $this->client->flush();
    }

    /**
     * @return list<array{name: string, kind: string, value: float, attrs: array<string, string>}>
     */
    public function metrics(): array
    {
        $metrics = [];
        $this->gauge($metrics, 'host.cpu.ratio', $this->hosts->cpuRatio());
        $this->gauge($metrics, 'host.cpu.limit', $this->hosts->cpuLimit());
        $this->memory($metrics);
        $this->disk($metrics);

        if ($this->fpmStatusUrl !== '') {
            $body = $this->status->get($this->fpmStatusUrl);
            if (is_string($body)) {
                foreach (FpmStatus::metrics($body) as $metric) {
                    $metrics[] = $metric + ['attrs' => $this->pool === '' ? [] : ['pool' => $this->pool]];
                }
            }
        }
        if ($this->opcacheUrl !== '') {
            $body = $this->status->get($this->opcacheUrl);
            if (is_string($body)) {
                foreach (OpcacheMetrics::fromBody($body) as $metric) {
                    $metrics[] = $metric + ['attrs' => []];
                }
            }
        }

        return $metrics;
    }

    /**
     * @param list<array{name: string, kind: string, value: float, attrs: array<string, string>}> $metrics
     */
    private function gauge(array &$metrics, string $name, ?float $value): void
    {
        if ($value === null) {
            return;
        }
        $metrics[] = ['name' => $name, 'kind' => 'gauge', 'value' => $value, 'attrs' => []];
    }

    /**
     * @param list<array{name: string, kind: string, value: float, attrs: array<string, string>}> $metrics
     */
    private function memory(array &$metrics): void
    {
        $pair = $this->hosts->memoryBytes();
        if ($pair === null) {
            $this->gauge($metrics, 'host.memory.ratio', $this->hosts->memoryRatio());

            return;
        }
        $this->gauge($metrics, 'host.memory.used_bytes', $pair['used']);
        $this->gauge($metrics, 'host.memory.limit_bytes', $pair['limit']);
        $this->gauge($metrics, 'host.memory.ratio', $pair['limit'] > 0 ? self::clamp($pair['used'] / $pair['limit']) : null);
    }

    /**
     * @param list<array{name: string, kind: string, value: float, attrs: array<string, string>}> $metrics
     */
    private function disk(array &$metrics): void
    {
        $pair = $this->hosts->diskBytes($this->diskPath);
        if ($pair === null) {
            $this->gauge($metrics, 'host.disk.ratio', $this->hosts->diskRatio($this->diskPath));

            return;
        }
        $this->gauge($metrics, 'host.disk.used_bytes', $pair['used']);
        $this->gauge($metrics, 'host.disk.total_bytes', $pair['total']);
        $this->gauge($metrics, 'host.disk.ratio', $pair['total'] > 0 ? self::clamp($pair['used'] / $pair['total']) : null);
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
