<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

/**
 * Host ratios. Tests supply a fake so they do not need /proc.
 */
interface HostReadings
{
    public function cpuRatio(): ?float;

    public function memoryRatio(): ?float;

    public function diskRatio(string $path): ?float;
}
