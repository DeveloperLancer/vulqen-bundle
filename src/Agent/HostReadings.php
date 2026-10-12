<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

/**
 * Host samples. Tests supply a fake so they do not need /proc.
 */
interface HostReadings
{
    public function cpuRatio(): ?float;

    public function cpuLimit(): ?float;

    public function memoryRatio(): ?float;

    /**
     * @return array{used: float, limit: float}|null
     */
    public function memoryBytes(): ?array;

    public function diskRatio(string $path): ?float;

    /**
     * @return array{used: float, total: float}|null
     */
    public function diskBytes(string $path): ?array;
}
