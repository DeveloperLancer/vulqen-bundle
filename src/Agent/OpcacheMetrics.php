<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

/**
 * Turns the JSON body of GET /_vulqen/opcache into metrics.
 * The status is read inside the FPM worker that served the endpoint.
 */
final class OpcacheMetrics
{
    /**
     * @return list<array{name: string, kind: string, value: float}>
     */
    public static function fromBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $metrics = [];
        foreach (['php.opcache.hit_ratio' => 'hit_ratio', 'php.opcache.memory_ratio' => 'memory_ratio'] as $name => $field) {
            if (!isset($decoded[$field]) || !is_numeric($decoded[$field])) {
                continue;
            }
            $metrics[] = ['name' => $name, 'kind' => 'gauge', 'value' => (float) $decoded[$field]];
        }

        return $metrics;
    }
}
