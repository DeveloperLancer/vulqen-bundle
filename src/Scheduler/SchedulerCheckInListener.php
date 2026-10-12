<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Scheduler;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vulqen\Sdk\Client;

/**
 * in_progress when a scheduled command starts, ok or error when it ends.
 * Registered only when the Scheduler component is installed.
 */
final class SchedulerCheckInListener implements EventSubscriberInterface
{
    private const PRE_RUN = 'Symfony\Component\Scheduler\Event\PreRunEvent';
    private const POST_RUN = 'Symfony\Component\Scheduler\Event\PostRunEvent';
    private const FAILURE = 'Symfony\Component\Scheduler\Event\FailureEvent';

    /** @var array<string, array{at: float, interval: int}> */
    private array $started = [];

    public function __construct(private readonly Client $client)
    {
    }

    public static function getSubscribedEvents(): array
    {
        if (!class_exists(self::PRE_RUN)) {
            return [];
        }

        return [
            self::PRE_RUN => 'onStart',
            self::POST_RUN => 'onFinish',
            self::FAILURE => 'onFailure',
        ];
    }

    public function onStart(object $event): void
    {
        if (!method_exists($event, 'getMessage') || !method_exists($event, 'getMessageContext')) {
            return;
        }
        $message = $event->getMessage();
        if (!is_object($message)) {
            return;
        }
        $name = ScheduleCheckIn::commandName($message);
        if ($name === null) {
            return;
        }
        $context = $event->getMessageContext();
        $interval = is_object($context)
            ? ScheduleCheckIn::intervalSeconds(self::instant($context, 'triggeredAt'), self::instant($context, 'nextTriggerAt'))
            : 60;
        $this->started[$name] = ['at' => microtime(true), 'interval' => $interval];
        $this->client->captureCheckIn($name, 'in_progress', null, $interval);
        $this->client->flush();
    }

    public function onFinish(object $event): void
    {
        $message = self::messageOf($event);
        if ($message === null) {
            return;
        }
        $failed = method_exists($event, 'getResult') && is_int($event->getResult()) && $event->getResult() !== 0;
        $this->finish($message, $failed);
    }

    public function onFailure(object $event): void
    {
        $message = self::messageOf($event);
        if ($message === null) {
            return;
        }
        $this->finish($message, true);
    }

    private function finish(object $message, bool $failed): void
    {
        $name = ScheduleCheckIn::commandName($message);
        if ($name === null) {
            return;
        }
        $started = $this->started[$name] ?? null;
        unset($this->started[$name]);
        $duration = $started === null ? null : max(0, (microtime(true) - $started['at']) * 1000);
        $this->client->captureCheckIn($name, $failed ? 'error' : 'ok', $duration, $started === null ? 60 : $started['interval']);
        $this->client->flush();
    }

    private static function messageOf(object $event): ?object
    {
        if (!method_exists($event, 'getMessage')) {
            return null;
        }
        $message = $event->getMessage();

        return is_object($message) ? $message : null;
    }

    private static function instant(object $context, string $name): ?\DateTimeImmutable
    {
        $value = get_object_vars($context)[$name] ?? null;

        return $value instanceof \DateTimeImmutable ? $value : null;
    }
}
