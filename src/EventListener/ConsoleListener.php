<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vulqen\Sdk\Client;

/**
 * Komenda konsoli jest transakcją console.command. messenger:consume zostaje bez niej:
 * każda wiadomość jest własną transakcją queue.process.
 */
final class ConsoleListener implements EventSubscriberInterface
{
    private const SKIPPED = [
        'messenger:consume' => true,
        'messenger:consume-messages' => true,
    ];

    public function __construct(private readonly Client $client)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }
        $name = $event->getCommand()?->getName() ?? '';
        if ($name === '' || isset(self::SKIPPED[$name])) {
            return;
        }
        $this->client->startTransaction($name, 'console.command');
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        if ($this->client->transaction()?->op !== 'console.command') {
            return;
        }
        $this->client->captureException($event->getError());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $transaction = $this->client->transaction();
        if ($transaction === null || $transaction->op !== 'console.command') {
            return;
        }
        if ($event->getExitCode() !== 0) {
            $transaction->markError();
        }
        $this->client->finishTransaction();
        $this->client->flush();
    }
}
