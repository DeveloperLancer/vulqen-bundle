<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

interface StatusFetch
{
    public function get(string $url): ?string;
}
