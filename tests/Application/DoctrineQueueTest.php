<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Message\ProcessPaymentEvent;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Runs against the transport built from the real MESSENGER_TRANSPORT_DSN (Doctrine on PostgreSQL),
 * not the in-memory one used by the other tests.
 */
final class DoctrineQueueTest extends KernelTestCase
{
    private Connection $connection;
    private TransportInterface $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM messenger_messages');

        $factory = $container->get('messenger.transport_factory');
        self::assertInstanceOf(TransportFactoryInterface::class, $factory);
        $serializer = $container->get('messenger.default_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? null;
        self::assertIsString($dsn);
        self::assertStringStartsWith('doctrine://', $dsn);

        $this->transport = $factory->createTransport($dsn, [], $serializer);
    }

    public function testMessageQueuedAfterQueueWasEmptyIsReceivedRightAway(): void
    {
        self::assertSame([], [...$this->transport->get()]);

        $this->transport->send(new Envelope(new ProcessPaymentEvent('evt_9f8d7c6b5a4321')));
        $received = [...$this->transport->get()];

        self::assertCount(1, $received);
        $message = $received[0]->getMessage();
        self::assertInstanceOf(ProcessPaymentEvent::class, $message);
        self::assertSame('evt_9f8d7c6b5a4321', $message->eventId);
    }

    public function testMessageSentInRolledBackTransactionIsNotQueued(): void
    {
        $this->connection->beginTransaction();
        $this->transport->send(new Envelope(new ProcessPaymentEvent('evt_9f8d7c6b5a4321')));
        $this->connection->rollBack();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertSame([], [...$this->transport->get()]);
    }
}
