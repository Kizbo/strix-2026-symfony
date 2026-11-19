<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PaymentEvent;
use App\Entity\PaymentEventStatus;
use App\Message\ProcessPaymentEvent;
use App\Service\PaymentNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A charge waits (parked) until the intent of its transaction is stored; the intent
 * releases parked charges once notified. Assumes a single worker process.
 */
#[AsMessageHandler]
final readonly class ProcessPaymentEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentNotifier $notifier,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessPaymentEvent $message): void
    {
        $event = $this->entityManager->find(PaymentEvent::class, $message->eventId);
        if (null === $event || $event->isNotified()) {
            return;
        }

        if ($event->isCharge() && !$this->intentStored($event->getTransactionId())) {
            $event->park();
            $this->entityManager->flush();

            return;
        }

        $this->notifier->send($event);
        $event->markNotified();
        $this->entityManager->flush();

        if ($event->isIntent()) {
            $this->releaseParkedCharges($event->getTransactionId());
        }
    }

    private function intentStored(string $transactionId): bool
    {
        return null !== $this->entityManager->getRepository(PaymentEvent::class)
            ->findOneBy(['transactionId' => $transactionId, 'eventType' => PaymentEvent::INTENT_EVENT]);
    }

    private function releaseParkedCharges(string $transactionId): void
    {
        $parked = $this->entityManager->getRepository(PaymentEvent::class)
            ->findBy(['transactionId' => $transactionId, 'status' => PaymentEventStatus::Parked], ['receivedAt' => 'ASC']);

        foreach ($parked as $charge) {
            $charge->release();
        }
        $this->entityManager->flush();

        foreach ($parked as $charge) {
            $this->bus->dispatch(new ProcessPaymentEvent($charge->getEventId()));
        }
    }
}
