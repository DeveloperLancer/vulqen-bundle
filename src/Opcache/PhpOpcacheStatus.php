<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Opcache;

/**
 * Reads OPcache inside the FPM worker that served the request.
 * A CLI call to opcache_get_status() would be a different pool (R-010).
 */
final class PhpOpcacheStatus
{
    /**
     * @return array{hit_ratio: float, memory_ratio: float}
     */
    public static function read(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['hit_ratio' => 0.0, 'memory_ratio' => 0.0];
        }

        $status = opcache_get_status(false);

        return self::fromStatus(is_array($status) ? $status : []);
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array{hit_ratio: float, memory_ratio: float}
     */
    public static function fromStatus(array $status): array
    {
        $stats = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $hits = is_numeric($stats['hits'] ?? null) ? (float) $stats['hits'] : 0.0;
        $misses = is_numeric($stats['misses'] ?? null) ? (float) $stats['misses'] : 0.0;
        $used = is_numeric($memory['used_memory'] ?? null) ? (float) $memory['used_memory'] : 0.0;
        $free = is_numeric($memory['free_memory'] ?? null) ? (float) $memory['free_memory'] : 0.0;

        return [
            'hit_ratio' => ($hits + $misses) > 0 ? $hits / ($hits + $misses) : 0.0,
            'memory_ratio' => ($used + $free) > 0 ? $used / ($used + $free) : 0.0,
        ];
    }
}
