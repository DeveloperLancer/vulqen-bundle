<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Agent;

final class NullStatusFetch implements StatusFetch
{
    public function get(string $url): ?string
    {
        return null;
    }
}
