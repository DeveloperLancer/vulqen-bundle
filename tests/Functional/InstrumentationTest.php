<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vulqen\Sdk\Client;
use Vulqen\SymfonyBundle\Tests\App\CapturingTransport;
use Vulqen\SymfonyBundle\Tests\App\TestKernel;

final class InstrumentationTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    public function testTransakcjaMaNazweTrasyStatusISpanySql(): void
    {
        $kernel = $this->kernel();
        $response = $this->request($kernel, '/orders/5');
        self::assertSame(200, $response->getStatusCode());

        $transactions = $this->transport($kernel)->itemsOfType('transaction');
        self::assertCount(1, $transactions);
        $transaction = $transactions[0];
        self::assertSame('app_order_show', $transaction['name']);
        self::assertSame('http.server', $transaction['op']);
        self::assertSame(200, $transaction['http_status']);
        self::assertSame('ok', $transaction['outcome']);
        self::assertIsInt($transaction['memory_peak_bytes'] ?? null);

        $selects = array_values(array_filter(
            $this->spans($transaction),
            static fn (array $span): bool => str_starts_with((string) $span['description'], 'SELECT id, note'),
        ));
        self::assertCount(2, $selects);
        self::assertSame('SELECT id, note FROM orders WHERE id = ?', $selects[0]['description']);
        self::assertSame($selects[0]['attrs']['db.statement'], $selects[1]['attrs']['db.statement']);
        self::assertSame('sqlite', $selects[0]['attrs']['db.system']);
        self::assertSame('db.query', $selects[0]['op']);
        self::assertSame($transaction['span_id'], $selects[0]['parent_span_id']);

        $descriptions = array_column($this->spans($transaction), 'description');
        self::assertContains('SELECT note FROM orders WHERE id = ?', $descriptions);
        self::assertStringNotContainsString(' 5', implode("\n", $descriptions));
    }

    public function testNieznanaTrasaToHttpUnmatched(): void
    {
        $kernel = $this->kernel();
        $response = $this->request($kernel, '/nie-ma-takiej');
        self::assertSame(404, $response->getStatusCode());

        $transport = $this->transport($kernel);
        $transactions = $transport->itemsOfType('transaction');
        self::assertCount(1, $transactions);
        self::assertSame('http.unmatched', $transactions[0]['name']);
        self::assertSame(404, $transactions[0]['http_status']);
        self::assertSame([], $transport->itemsOfType('error'), '404 nie jest błędem aplikacji.');
    }

    public function testWyjatekMaKlaseRamkiIInApp(): void
    {
        $kernel = $this->kernel();
        $response = $this->request($kernel, '/fail');
        self::assertSame(500, $response->getStatusCode());

        $transport = $this->transport($kernel);
        $errors = $transport->itemsOfType('error');
        self::assertCount(1, $errors);
        $exception = $errors[0]['exception'];
        self::assertSame(\LogicException::class, $exception['class']);
        self::assertSame('Zamówienie nie ma pozycji', $exception['message']);
        self::assertNotEmpty($exception['frames']);
        self::assertSame('tests/App/TestController.php', $exception['frames'][0]['file']);
        self::assertTrue($exception['frames'][0]['in_app']);
        self::assertContains(false, array_column($exception['frames'], 'in_app'), 'Ramki z vendor/ nie są in_app.');

        $transactions = $transport->itemsOfType('transaction');
        self::assertCount(1, $transactions);
        self::assertSame('app_fail', $transactions[0]['name']);
        self::assertSame('error', $transactions[0]['outcome']);
        self::assertSame(500, $transactions[0]['http_status']);
        self::assertSame($transactions[0]['trace_id'], $errors[0]['trace_id']);
        self::assertSame($transactions[0]['id'], $errors[0]['transaction_id']);
    }

    public function testNaglowekAuthorizationNieTrafiaDoZdarzenia(): void
    {
        $kernel = $this->kernel();
        $this->request($kernel, '/leak', ['HTTP_AUTHORIZATION' => 'Bearer sekret-tokenu-123']);

        $sent = implode("\n", $this->transport($kernel)->sent);
        self::assertNotSame('', $sent);
        self::assertStringNotContainsString('sekret-tokenu-123', $sent);
        self::assertStringNotContainsString('Bearer', $sent);
    }

    public function testWykluczonaTrasaNieWysylaNic(): void
    {
        $kernel = $this->kernel(['excluded_routes' => ['app_health']]);
        $response = $this->request($kernel, '/health');
        self::assertSame(200, $response->getStatusCode());

        self::assertSame([], $this->transport($kernel)->sent);
    }

    public function testEnabledFalseNieRejestrujeSluchaczy(): void
    {
        $kernel = $this->kernel(['enabled' => false]);
        $response = $this->request($kernel, '/orders/5');
        self::assertSame(200, $response->getStatusCode());

        $container = $kernel->getContainer();
        self::assertFalse($container->has('vulqen.client'));
        self::assertSame([], $this->transport($kernel)->sent);
    }

    public function testPustyDsnNicNieWysyla(): void
    {
        $kernel = $this->kernel(['dsn' => '']);
        $response = $this->request($kernel, '/fail');
        self::assertSame(500, $response->getStatusCode());

        $client = $kernel->getContainer()->get('vulqen.client');
        self::assertInstanceOf(Client::class, $client);
        self::assertFalse($client->isEnabled());
        self::assertSame([], $this->transport($kernel)->sent);
    }

    public function testBezDoctrineBundleDziala(): void
    {
        $kernel = $this->kernel([], false);
        $this->request($kernel, '/orders/5');

        $transactions = $this->transport($kernel)->itemsOfType('transaction');
        self::assertCount(1, $transactions);
        self::assertSame('app_order_show', $transactions[0]['name']);
        self::assertSame([], $this->spans($transactions[0]));
    }

    /**
     * R-005: drugie żądanie w tym samym kernelu nie dziedziczy transakcji, spanów ani błędów pierwszego.
     */
    public function testDrugieZadanieWTymSamymKerneluNieDziedziczyStanu(): void
    {
        $kernel = $this->kernel();
        $transport = $this->transport($kernel);

        $kernel->handle(Request::create('/fail'));
        self::assertSame([], $transport->sent, 'Bez kernel.terminate nic nie wyszło.');

        $this->request($kernel, '/orders/7');

        $items = $transport->items();
        self::assertCount(1, $items);
        self::assertSame('transaction', $items[0]['type']);
        self::assertSame('app_order_show', $items[0]['name']);
        self::assertSame('ok', $items[0]['outcome']);

        $this->request($kernel, '/orders/8');
        $transactions = $transport->itemsOfType('transaction');
        self::assertCount(2, $transactions);
        self::assertNotSame($transactions[0]['trace_id'], $transactions[1]['trace_id']);
        self::assertCount(count($this->spans($transactions[0])), $this->spans($transactions[1]));
    }

    public function testPadajacyTransportNieZmieniaOdpowiedzi(): void
    {
        $kernel = $this->kernel();
        $this->transport($kernel)->throw = true;

        $response = $this->request($kernel, '/orders/5');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"id":5}', $response->getContent());
        self::assertCount(1, $this->transport($kernel)->sent);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = [], bool $doctrine = true): TestKernel
    {
        $kernel = new TestKernel($config + ['dsn' => TestKernel::DSN, 'environment' => 'test'], $doctrine);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * @param array<string, string> $server
     */
    private function request(TestKernel $kernel, string $uri, array $server = []): Response
    {
        $request = Request::create($uri, 'GET', [], [], [], $server + ['REQUEST_TIME_FLOAT' => microtime(true)]);
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

    /**
     * @param array<string, mixed> $transaction
     *
     * @return list<array<string, mixed>>
     */
    private function spans(array $transaction): array
    {
        $spans = $transaction['spans'] ?? [];
        \assert(is_array($spans));

        return array_values($spans);
    }
}
