<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * traceparent niesiony na kopercie od wysyłki do odbioru.
 */
final class VulqenTraceStamp implements StampInterface
{
    public function __construct(public readonly string $traceparent)
    {
    }
}
