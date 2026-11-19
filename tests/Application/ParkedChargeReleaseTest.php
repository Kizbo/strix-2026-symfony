<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Message\ProcessPaymentEvent;
use App\MessageHandler\ProcessPaymentEventHandler;
use App\Tests\Support\NotifierStub;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ParkedChargeReleaseTest extends KernelTestCase
{
    public function testRetriedIntentReleasesChargesLeftParkedByInterruptedRelease(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('TRUNCATE payment_event');
        // State after a crash between marking the intent notified and releasing its charge.
        $connection->executeStatement(
            "INSERT INTO payment_event (event_id, event_type, transaction_id, payload, status, received_at) VALUES
             ('evt_intent', 'payment_intent.created', 'txn_1', '{}', 'notified', NOW()),
             ('evt_charge', 'charge.successful', 'txn_1', '{}', 'parked', NOW())",
        );

        $handler = $container->get(ProcessPaymentEventHandler::class);
        self::assertInstanceOf(ProcessPaymentEventHandler::class, $handler);
        $handler(new ProcessPaymentEvent('evt_intent'));

        self::assertSame('received', $connection->fetchOne("SELECT status FROM payment_event WHERE event_id = 'evt_charge'"));
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertEquals([new ProcessPaymentEvent('evt_charge')], $queued);

        $notifier = $container->get(NotifierStub::class);
        self::assertInstanceOf(NotifierStub::class, $notifier);
        self::assertSame([], $notifier->requests, 'The intent must not be notified again.');
    }
}
