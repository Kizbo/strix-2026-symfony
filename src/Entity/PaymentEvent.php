<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_payment_event_transaction_id', columns: ['transaction_id'])]
class PaymentEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        private string $eventId,
        #[ORM\Column(length: 255)]
        private string $eventType,
        #[ORM\Column(length: 255)]
        private string $transactionId,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
        #[ORM\Column(length: 20, enumType: PaymentEventStatus::class)]
        private PaymentEventStatus $status,
        #[ORM\Column]
        private \DateTimeImmutable $receivedAt,
    ) {
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): PaymentEventStatus
    {
        return $this->status;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
