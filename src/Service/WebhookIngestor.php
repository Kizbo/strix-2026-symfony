<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PaymentEventStatus;
use App\Message\ProcessPaymentEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class WebhookIngestor
{
    public function __construct(
        private Connection $connection,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Stores the event once per event_id and queues it in the same transaction.
     * A duplicate, including one losing a concurrent INSERT race, is a no-op.
     *
     * @param array<string, mixed> $payload
     */
    public function ingest(string $eventId, string $eventType, string $transactionId, array $payload): void
    {
        $this->connection->transactional(function (Connection $connection) use ($eventId, $eventType, $transactionId, $payload): void {
            $inserted = $connection->executeStatement(
                'INSERT INTO payment_event (event_id, event_type, transaction_id, payload, status, received_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (event_id) DO NOTHING',
                [
                    $eventId,
                    $eventType,
                    $transactionId,
                    json_encode($payload, \JSON_THROW_ON_ERROR),
                    PaymentEventStatus::Received->value,
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );

            if (1 === $inserted) {
                $this->bus->dispatch(new ProcessPaymentEvent($eventId));
            }
        });
    }
}
