<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vulqen\SymfonyBundle\Tests\App\CapturingTransport;
use Vulqen\SymfonyBundle\Tests\App\RecordingHttpClient;
use Vulqen\SymfonyBundle\Tests\App\SendInvoice;
use Vulqen\SymfonyBundle\Tests\App\SendInvoiceHandler;
use Vulqen\SymfonyBundle\Tests\App\TestKernel;

final class TraceInstrumentationTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        RecordingHttpClient::$calls = [];
        SendInvoiceHandler::$traceId = null;
        SendInvoiceHandler::$name = null;
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    public function testAMessengerMessageKeepsTheRequestTrace(): void
    {
        $kernel = $this->kernel(messenger: true);
        $response = $this->request($kernel, '/publish');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('sent', $response->getContent());

        $transport = $this->transport($kernel);
        $http = $this->only($transport->itemsOfType('transaction'), 'http.server');
        $worker = $this->only($transport->itemsOfType('transaction'), 'queue.process');

        self::assertSame($http['trace_id'], $worker['trace_id']);
        self::assertSame(SendInvoice::class, $worker['name']);
        self::assertSame(SendInvoiceHandler::$traceId, $http['trace_id']);
        self::assertSame(SendInvoice::class, SendInvoiceHandler::$name);

        $publish = array_values(array_filter(
            $http['spans'],
            static fn (array $span): bool => $span['op'] === 'queue.publish',
        ));
        self::assertCount(1, $publish);
        self::assertSame($publish[0]['span_id'], $worker['parent_span_id']);
    }

    public function testOutboundHttpCarriesTraceparentExceptTheIngestHost(): void
    {
        $kernel = $this->kernel(http: true);
        $this->request($kernel, '/remote');

        self::assertCount(2, RecordingHttpClient::$calls);
        $header = self::traceparent(RecordingHttpClient::$calls[0]['headers']);
        self::assertNotNull($header);
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $header);
        self::assertNull(self::traceparent(RecordingHttpClient::$calls[1]['headers']));

        $transaction = $this->only($this->transport($kernel)->itemsOfType('transaction'), 'http.server');
        $clients = array_values(array_filter(
            $transaction['spans'],
            static fn (array $span): bool => $span['op'] === 'http.client',
        ));
        self::assertCount(1, $clients);
        self::assertStringContainsString('api.example.test', $clients[0]['description']);
        self::assertStringNotContainsString('ingest.example.test', strtolower(implode("\n", array_column($transaction['spans'], 'description'))));
        self::assertStringEndsWith($clients[0]['span_id'].'-01', $header);
    }

    public function testAConsoleCommandIsATransactionAndConsumeIsNot(): void
    {
        $kernel = $this->kernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'app:ping']), new BufferedOutput()));

        $transactions = $this->transport($kernel)->itemsOfType('transaction');
        self::assertCount(1, $transactions);
        self::assertSame('app:ping', $transactions[0]['name']);
        self::assertSame('console.command', $transactions[0]['op']);

        $application->run(new ArrayInput(['command' => 'messenger:consume']), new BufferedOutput());
        self::assertCount(1, $this->transport($kernel)->itemsOfType('transaction'));
    }

    private function kernel(bool $messenger = false, bool $http = false): TestKernel
    {
        $kernel = new TestKernel(
            ['dsn' => TestKernel::DSN, 'environment' => 'test'],
            false,
            $messenger,
            $http,
        );
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function request(TestKernel $kernel, string $uri): Response
    {
        $request = Request::create($uri, 'GET', [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true)]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function transport(TestKernel $kernel): CapturingTransport
    {
        $transport = $kernel->getContainer()->get('vulqen.transport');
        self::assertInstanceOf(CapturingTransport::class, $transport);

        return $transport;
    }

    private static function traceparent(mixed $headers): ?string
    {
        if (!is_array($headers)) {
            return null;
        }
        if (isset($headers['traceparent']) && is_string($headers['traceparent'])) {
            return $headers['traceparent'];
        }
        foreach ($headers as $header) {
            if (is_string($header) && str_starts_with(strtolower($header), 'traceparent:')) {
                return trim(substr($header, strlen('traceparent:')));
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $transactions
     *
     * @return array<string, mixed>
     */
    private function only(array $transactions, string $op): array
    {
        $matched = array_values(array_filter(
            $transactions,
            static fn (array $item): bool => ($item['op'] ?? null) === $op,
        ));
        self::assertCount(1, $matched, $op);

        return $matched[0];
    }
}
