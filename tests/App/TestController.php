<?php

declare(strict_types=1);

namespace Vulqen\SymfonyBundle\Tests\App;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class TestController
{
    public function __construct(private readonly ?Connection $connection = null)
    {
    }

    public function order(string $id): Response
    {
        $id = (int) $id;
        $this->connection?->executeStatement('CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY, note TEXT)');
        $this->connection?->executeQuery('SELECT id, note FROM orders WHERE id = '.$id)->fetchAllAssociative();
        $this->connection?->executeQuery('SELECT id, note FROM orders WHERE id = '.($id + 1))->fetchAllAssociative();
        $this->connection?->fetchOne('SELECT note FROM orders WHERE id = ?', [$id]);

        return new JsonResponse(['id' => $id]);
    }

    public function fail(): Response
    {
        throw new \LogicException('Zamówienie nie ma pozycji');
    }

    public function leak(): Response
    {
        throw new \RuntimeException('Upstream odrzucił Authorization: Bearer sekret-tokenu-123');
    }

    public function health(): Response
    {
        $this->connection?->executeQuery('SELECT 1');

        return new Response('ok');
    }
}
