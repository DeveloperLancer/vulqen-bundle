<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\App;

use Vulqen\Sdk\Client;

final class SendInvoiceHandler
{
    public static ?string $traceId = null;

    public static ?string $name = null;

    public function __construct(private readonly Client $client)
    {
    }

    public function __invoke(SendInvoice $message): void
    {
        $transaction = $this->client->transaction();
        self::$traceId = $transaction?->traceId;
        self::$name = $transaction?->name();
    }
}
