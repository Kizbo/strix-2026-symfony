<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Message\ProcessPaymentEvent;
use App\Service\WebhookIngestor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WebhookIngestorTest extends KernelTestCase
{
    private const string EVENT_ID = 'evt_9f8d7c6b5a4321';

    private Connection $connection;
    private WebhookIngestor $ingestor;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('TRUNCATE payment_event');

        $ingestor = $container->get(WebhookIngestor::class);
        self::assertInstanceOf(WebhookIngestor::class, $ingestor);
        $this->ingestor = $ingestor;

        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
    }

    public function testStoresContractSampleAndQueuesOneMessage(): void
    {
        $this->ingestor->ingest(self::EVENT_ID, 'charge.successful', 'txn_8a7b6c5d4e3f', $this->contractSample());

        $rows = $this->connection->fetchAllAssociative('SELECT event_id, event_type, transaction_id, payload, status FROM payment_event');
        self::assertCount(1, $rows);
        self::assertSame(self::EVENT_ID, $rows[0]['event_id']);
        self::assertSame('charge.successful', $rows[0]['event_type']);
        self::assertSame('txn_8a7b6c5d4e3f', $rows[0]['transaction_id']);
        self::assertSame('received', $rows[0]['status']);
        self::assertIsString($rows[0]['payload']);
        self::assertEquals($this->contractSample(), json_decode($rows[0]['payload'], true));

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ProcessPaymentEvent::class, $message);
        self::assertSame(self::EVENT_ID, $message->eventId);
    }

    public function testDuplicateDeliveryStoresOneRowAndQueuesOneMessage(): void
    {
        $this->ingestor->ingest(self::EVENT_ID, 'charge.successful', 'txn_8a7b6c5d4e3f', $this->contractSample());
        $this->ingestor->ingest(self::EVENT_ID, 'charge.successful', 'txn_8a7b6c5d4e3f', $this->contractSample());

        self::assertSame(1, $this->countRows());
        self::assertCount(1, $this->transport->getSent());
    }

    public function testInsertLosingRaceToConcurrentRequestIsTreatedAsDuplicate(): void
    {
        // Row committed by a concurrent request after this one passed any read-side check.
        $this->connection->executeStatement(
            "INSERT INTO payment_event (event_id, event_type, transaction_id, payload, status, received_at) VALUES (?, 'charge.successful', 'txn_8a7b6c5d4e3f', '{}', 'received', NOW())",
            [self::EVENT_ID],
        );

        $this->ingestor->ingest(self::EVENT_ID, 'charge.successful', 'txn_8a7b6c5d4e3f', $this->contractSample());

        self::assertSame(1, $this->countRows());
        self::assertCount(0, $this->transport->getSent());
    }

    public function testDatabaseEnforcesUniqueEventId(): void
    {
        $insert = "INSERT INTO payment_event (event_id, event_type, transaction_id, payload, status, received_at) VALUES ('evt_x', 'charge.successful', 'txn_x', '{}', 'received', NOW())";
        $this->connection->executeStatement($insert);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement($insert);
    }

    /**
     * @return array<string, mixed>
     */
    private function contractSample(): array
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/charge-successful.json');
        self::assertIsString($json);
        $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    private function countRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM payment_event');
    }
}
