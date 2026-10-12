<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

/**
 * cgroup limit files when they exist, otherwise /proc. Disk uses the configured path.
 */
final class ProcHostReader implements HostReadings
{
    /** @var array{total: float, idle: float}|null */
    private ?array $previousCpu = null;

    private ?float $previousUsageUsec = null;

    private ?float $previousWall = null;

    public function __construct(private readonly string $root = '')
    {
    }

    public function cpuRatio(): ?float
    {
        $cgroup = $this->cgroupCpu();
        if ($cgroup !== null) {
            return $cgroup;
        }

        $sample = $this->procCpu();
        if ($sample === null) {
            return null;
        }
        $previous = $this->previousCpu;
        $this->previousCpu = $sample;
        if ($previous === null) {
            return null;
        }
        $total = $sample['total'] - $previous['total'];
        $idle = $sample['idle'] - $previous['idle'];
        if ($total <= 0) {
            return null;
        }

        return self::clamp(1 - ($idle / $total));
    }

    public function memoryRatio(): ?float
    {
        $current = $this->read('/sys/fs/cgroup/memory.current');
        $max = $this->read('/sys/fs/cgroup/memory.max');
        if ($current !== null && $max !== null && is_numeric(trim($current)) && is_numeric(trim($max)) && (float) $max > 0) {
            return self::clamp((float) $current / (float) $max);
        }

        $info = $this->read('/proc/meminfo');
        if ($info === null) {
            return null;
        }
        $total = self::meminfo($info, 'MemTotal');
        $available = self::meminfo($info, 'MemAvailable');
        if ($total === null || $available === null || $total <= 0) {
            return null;
        }

        return self::clamp(($total - $available) / $total);
    }

    public function diskRatio(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        return self::clamp(($total - $free) / $total);
    }

    private function cgroupCpu(): ?float
    {
        $stat = $this->read('/sys/fs/cgroup/cpu.stat');
        $max = $this->read('/sys/fs/cgroup/cpu.max');
        if ($stat === null || $max === null || !preg_match('/^(\d+)\s+(\d+)/', trim($max), $quota)) {
            return null;
        }
        if (!preg_match('/usage_usec\s+(\d+)/', $stat, $usage)) {
            return null;
        }
        $used = (float) $usage[1];
        $now = microtime(true);
        $previousUsed = $this->previousUsageUsec;
        $previousWall = $this->previousWall;
        $this->previousUsageUsec = $used;
        $this->previousWall = $now;
        if ($previousUsed === null || $previousWall === null) {
            return null;
        }
        $elapsedUsec = ($now - $previousWall) * 1_000_000;
        $period = (float) $quota[2];
        $limit = (float) $quota[1];
        if ($elapsedUsec <= 0 || $period <= 0) {
            return null;
        }

        return self::clamp(($used - $previousUsed) / ($elapsedUsec * $limit / $period));
    }

    /**
     * @return array{total: float, idle: float}|null
     */
    private function procCpu(): ?array
    {
        $stat = $this->read('/proc/stat');
        if ($stat === null || !preg_match('/^cpu\s+(.+)$/m', $stat, $matches)) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($matches[1])) ?: [];
        $numbers = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
            $numbers[] = (float) $part;
        }
        if (count($numbers) < 4) {
            return null;
        }
        $idle = $numbers[3] + ($numbers[4] ?? 0);

        return ['total' => array_sum($numbers), 'idle' => $idle];
    }

    private function read(string $path): ?string
    {
        $full = $this->root.$path;
        if (!is_file($full)) {
            return null;
        }
        $contents = @file_get_contents($full);

        return is_string($contents) ? $contents : null;
    }

    private static function meminfo(string $info, string $key): ?float
    {
        if (!preg_match('/^'.$key.':\s+(\d+)/m', $info, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
