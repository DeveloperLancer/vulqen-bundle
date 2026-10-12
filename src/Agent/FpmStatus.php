<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

/**
 * Reads an FPM status page (text or JSON). The counter max children reached
 * is the value the pool reports, not a guess.
 */
final class FpmStatus
{
    /**
     * @return list<array{name: string, kind: string, value: float}>
     */
    public static function metrics(string $body): array
    {
        $decoded = json_decode($body, true);
        $map = is_array($decoded) ? self::flatten($decoded) : self::text($body);

        $metrics = [];
        foreach ([
            'listen queue' => ['phpfpm.listen_queue', 'gauge'],
            'active processes' => ['phpfpm.active', 'gauge'],
            'idle processes' => ['phpfpm.idle', 'gauge'],
            'max children reached' => ['phpfpm.max_children_reached', 'counter'],
        ] as $label => [$name, $kind]) {
            if (!isset($map[$label]) || !is_numeric($map[$label])) {
                continue;
            }
            $metrics[] = ['name' => $name, 'kind' => $kind, 'value' => (float) $map[$label]];
        }

        return $metrics;
    }

    /**
     * @return array<string, string>
     */
    private static function text(string $body): array
    {
        $map = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $map[self::label($key)] = trim($value);
        }

        return $map;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, string>
     */
    private static function flatten(array $decoded): array
    {
        $map = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $map[self::label($key)] = (string) $value;
        }

        return $map;
    }

    private static function label(string $key): string
    {
        $label = strtolower(trim(str_replace(['_', '-'], ' ', $key)));

        return preg_replace('/\s+/', ' ', $label) ?? $label;
    }
}
