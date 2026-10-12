<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Scheduler;

/**
 * Monitor name is the scheduled command. The interval is the gap until the next trigger.
 */
final class ScheduleCheckIn
{
    private const SKIPPED = [
        'messenger:consume' => true,
        'messenger:consume-messages' => true,
        'vulqen:agent' => true,
    ];

    public static function commandName(object $message): ?string
    {
        if (!class_exists(\Symfony\Component\Console\Messenger\RunCommandMessage::class)) {
            return null;
        }
        if (!$message instanceof \Symfony\Component\Console\Messenger\RunCommandMessage) {
            return null;
        }
        $token = strtok(trim($message->input), " \t");
        if (!is_string($token) || isset(self::SKIPPED[$token])) {
            return null;
        }

        return $token;
    }

    public static function intervalSeconds(?\DateTimeImmutable $triggeredAt, ?\DateTimeImmutable $nextTriggerAt): int
    {
        if ($triggeredAt === null || $nextTriggerAt === null) {
            return 60;
        }
        $seconds = $nextTriggerAt->getTimestamp() - $triggeredAt->getTimestamp();

        return $seconds >= 1 ? $seconds : 60;
    }
}
