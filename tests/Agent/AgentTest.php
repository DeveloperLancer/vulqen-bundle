<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Vulqen\Sdk\Client;
use Vulqen\Sdk\Options;
use Vulqen\SymfonyBundle\Agent\AgentCommand;
use Vulqen\SymfonyBundle\Agent\AgentCycle;
use Vulqen\SymfonyBundle\Agent\FpmStatus;
use Vulqen\SymfonyBundle\Agent\HostReadings;
use Vulqen\SymfonyBundle\Agent\OpcacheMetrics;
use Vulqen\SymfonyBundle\Agent\ProcHostReader;
use Vulqen\SymfonyBundle\Agent\StatusFetch;
use Vulqen\SymfonyBundle\Opcache\PhpOpcacheStatus;
use Vulqen\SymfonyBundle\Scheduler\ScheduleCheckIn;

final class AgentTest extends TestCase
{
    private const FPM_ONE = <<<'TXT'
pool:                 www
listen queue:         0
idle processes:       2
active processes:     3
max children reached: 1
TXT;

    private const FPM_FOUR = <<<'TXT'
pool:                 www
listen queue:         0
idle processes:       1
active processes:     8
max children reached: 4
TXT;

    public function testTheFpmParserReadsTheMaxChildrenIncrease(): void
    {
        $first = $this->counter(FpmStatus::metrics(self::FPM_ONE));
        $second = $this->counter(FpmStatus::metrics(self::FPM_FOUR));

        self::assertSame(1.0, $first);
        self::assertSame(4.0, $second);
        self::assertSame(3.0, $second - $first);
    }

    public function testTheAgentCyclePassesTheFpmBodyThroughTheParser(): void
    {
        $fetch = new class implements StatusFetch {
            public function get(string $url): ?string
            {
                return $url === 'http://127.0.0.1/status' ? AgentTest::fpmFour() : null;
            }
        };
        $cycle = new AgentCycle(
            new Client(new Options(dsn: '')),
            $this->quietHost(),
            $fetch,
            'http://127.0.0.1/status',
            '',
            sys_get_temp_dir(),
            'www',
        );

        $metrics = $cycle->metrics();
        $counter = null;
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'phpfpm.max_children_reached') {
                $counter = $metric;
            }
        }

        self::assertNotNull($counter);
        self::assertSame(4.0, $counter['value']);
        self::assertSame('counter', $counter['kind']);
        self::assertSame(['pool' => 'www'], $counter['attrs']);
    }

    public function testOpcacheMetricsEqualTheEndpointBodyAndTheAgentDoesNotCallOpcache(): void
    {
        $status = [
            'opcache_statistics' => ['hits' => 80, 'misses' => 20],
            'memory_usage' => ['used_memory' => 30, 'free_memory' => 70],
        ];
        $body = json_encode(PhpOpcacheStatus::fromStatus($status), \JSON_THROW_ON_ERROR);
        $metrics = [];
        foreach (OpcacheMetrics::fromBody($body) as $metric) {
            $metrics[$metric['name']] = $metric['value'];
        }

        self::assertEqualsWithDelta(0.8, $metrics['php.opcache.hit_ratio'], 0.000001);
        self::assertEqualsWithDelta(0.3, $metrics['php.opcache.memory_ratio'], 0.000001);

        $agent = dirname(__DIR__, 2).'/src/Agent';
        foreach (glob($agent.'/*.php') ?: [] as $file) {
            self::assertStringNotContainsString('opcache_get_status', (string) file_get_contents($file), $file);
        }
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2).'/src/Opcache/PhpOpcacheStatus.php');
        self::assertStringContainsString('opcache_get_status', $endpoint);
    }

    public function testAnEmptyServerNameStopsBeforeAnyReading(): void
    {
        $hosts = new class implements HostReadings {
            public bool $called = false;

            public function cpuRatio(): ?float
            {
                $this->called = true;

                return null;
            }

            public function memoryRatio(): ?float
            {
                $this->called = true;

                return null;
            }

            public function diskRatio(string $path): ?float
            {
                $this->called = true;

                return null;
            }
        };
        $command = new AgentCommand(new AgentCycle(
            new Client(new Options(dsn: '')),
            $hosts,
            new class implements StatusFetch {
                public function get(string $url): ?string
                {
                    throw new \RuntimeException('status must not be fetched');
                }
            },
            'http://127.0.0.1/status',
            'http://127.0.0.1/_vulqen/opcache',
            sys_get_temp_dir(),
            'www',
        ), '', 1, 0);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('server_name is empty.', $tester->getDisplay());
        self::assertFalse($hosts->called);
    }

    public function testScheduleCheckInUsesTheCommandAndTheGapUntilTheNextTrigger(): void
    {
        self::assertSame('app:report', ScheduleCheckIn::commandName(new RunCommandMessage('app:report --env=prod')));
        self::assertNull(ScheduleCheckIn::commandName(new RunCommandMessage('messenger:consume ingest')));
        self::assertNull(ScheduleCheckIn::commandName(new RunCommandMessage('vulqen:agent')));

        $triggered = new \DateTimeImmutable('2026-10-11 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame(900, ScheduleCheckIn::intervalSeconds($triggered, $triggered->modify('+15 minutes')));
        self::assertSame(60, ScheduleCheckIn::intervalSeconds(null, null));
    }

    public function testCgroupMemoryDoesNotNeedProc(): void
    {
        $root = sys_get_temp_dir().'/vulqen-host-'.bin2hex(random_bytes(4));
        $dir = $root.'/sys/fs/cgroup';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/memory.current', "250\n");
        file_put_contents($dir.'/memory.max', "1000\n");

        try {
            self::assertEqualsWithDelta(0.25, (new ProcHostReader($root))->memoryRatio(), 0.000001);
        } finally {
            unlink($dir.'/memory.current');
            unlink($dir.'/memory.max');
            rmdir($dir);
            rmdir($root.'/sys/fs');
            rmdir($root.'/sys');
            rmdir($root);
        }
    }

    public static function fpmFour(): string
    {
        return self::FPM_FOUR;
    }

    /**
     * @param list<array{name: string, kind: string, value: float}> $metrics
     */
    private function counter(array $metrics): float
    {
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'phpfpm.max_children_reached') {
                return $metric['value'];
            }
        }

        self::fail('max children reached is missing');
    }

    private function quietHost(): HostReadings
    {
        return new class implements HostReadings {
            public function cpuRatio(): ?float
            {
                return null;
            }

            public function memoryRatio(): ?float
            {
                return null;
            }

            public function diskRatio(string $path): ?float
            {
                return null;
            }
        };
    }
}
