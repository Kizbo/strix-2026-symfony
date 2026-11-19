<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Message\ProcessPaymentEvent;
use App\MessageHandler\ProcessPaymentEventHandler;
use App\Service\WebhookIngestor;
use App\Tests\Support\NotifierStub;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class ProcessPaymentEventHandlerTest extends KernelTestCase
{
    private const string CHARGE_ID = 'evt_9f8d7c6b5a4321';
    private const string INTENT_ID = 'evt_1a2b3c4d5e6f70';

    private Connection $connection;
    private NotifierStub $notifier;

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('TRUNCATE payment_event');

        $notifier = self::getContainer()->get(NotifierStub::class);
        self::assertInstanceOf(NotifierStub::class, $notifier);
        $this->notifier = $notifier;
    }

    public function testChargeWithoutIntentIsParkedAndNotNotified(): void
    {
        $this->ingest($this->charge());

        $this->drainQueue();

        self::assertSame('parked', $this->eventStatus(self::CHARGE_ID));
        self::assertSame([], $this->notifier->requests);
    }

    public function testIntentArrivingLaterReleasesParkedCharge(): void
    {
        $this->ingest($this->charge());
        $this->drainQueue();

        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 200]));
        $this->ingest($this->intent());
        $this->drainQueue();

        self::assertSame('notified', $this->eventStatus(self::INTENT_ID));
        self::assertSame('notified', $this->eventStatus(self::CHARGE_ID));
        self::assertSame([self::INTENT_ID, self::CHARGE_ID], $this->notifiedEventIds());
    }

    public function testChargeAfterIntentIsNotifiedRightAway(): void
    {
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 200]));

        $this->ingest($this->intent());
        $this->ingest($this->charge());
        $this->drainQueue();

        self::assertSame('notified', $this->eventStatus(self::CHARGE_ID));
        self::assertSame([self::INTENT_ID, self::CHARGE_ID], $this->notifiedEventIds());
    }

    public function testEventWithoutChargePrefixNeverWaits(): void
    {
        $refund = $this->charge();
        $refund['event'] = 'refund.created';
        $refund['event_id'] = 'evt_refund_1';
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]));

        $this->ingest($refund);
        $this->drainQueue();

        self::assertSame('notified', $this->eventStatus('evt_refund_1'));
        self::assertSame(['evt_refund_1'], $this->notifiedEventIds());
    }

    public function testTestModeEventIsNotified(): void
    {
        $charge = $this->charge();
        $charge['livemode'] = false;
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 200]));

        $this->ingest($this->intent());
        $this->ingest($charge);
        $this->drainQueue();

        self::assertSame('notified', $this->eventStatus(self::CHARGE_ID));
        self::assertFalse($this->notificationFor(self::CHARGE_ID)['livemode']);
    }

    public function testRefundedChargeWithIntentIsNotified(): void
    {
        $refunded = $this->charge();
        $refunded['event'] = 'charge.refunded';
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 200]));

        $this->ingest($this->intent());
        $this->ingest($refunded);
        $this->drainQueue();

        self::assertSame('notified', $this->eventStatus(self::CHARGE_ID));
        self::assertSame('charge.refunded', $this->notificationFor(self::CHARGE_ID)['event']);
    }

    public function testNotificationContractWithoutPersonalData(): void
    {
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]), new MockResponse('', ['http_code' => 200]));

        $this->ingest($this->intent());
        $this->ingest($this->charge());
        $this->drainQueue();

        $request = $this->notifier->requests[1];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://notifier.test/notifications', $request['url']);

        $notification = $this->notificationFor(self::CHARGE_ID);
        self::assertIsString($notification['received_at']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $notification['received_at']));
        unset($notification['received_at']);
        self::assertSame([
            'event_id' => self::CHARGE_ID,
            'event' => 'charge.successful',
            'livemode' => true,
            'transaction_id' => 'txn_8a7b6c5d4e3f',
            'order_id' => 'ORD-2024-8839',
            'amount' => ['value' => 29990, 'currency' => 'PLN'],
            'customer_company' => 'Kowalski Dev Ltd',
        ], $notification);
        self::assertStringNotContainsString('jan.kowalski@example.com', $request['body']);
        self::assertStringNotContainsString('"Kowalski"', $request['body']);
        self::assertStringNotContainsString('"Jan"', $request['body']);
    }

    public function testDeliveredOnThirdAttemptAfterTwoServiceUnavailable(): void
    {
        $this->notifier->respondWith(
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 200]),
        );
        $this->ingest($this->intent());

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->assertRetryableFailure(self::INTENT_ID);
        }
        $this->handle(self::INTENT_ID);

        self::assertSame('notified', $this->eventStatus(self::INTENT_ID));
        self::assertCount(3, $this->notifier->requests);
    }

    public function testTimeoutIsRetryable(): void
    {
        $this->notifier->respondWith(new MockResponse('', ['error' => 'Idle timeout reached']));
        $this->ingest($this->intent());

        $this->assertRetryableFailure(self::INTENT_ID);
    }

    public function testBadRequestFromNotifierIsNotRetried(): void
    {
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 400]));
        $this->ingest($this->intent());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handle(self::INTENT_ID);
    }

    public function testAlreadyNotifiedEventIsNotSentAgain(): void
    {
        $this->notifier->respondWith(new MockResponse('', ['http_code' => 200]));
        $this->ingest($this->intent());

        $this->handle(self::INTENT_ID);
        $this->handle(self::INTENT_ID);

        self::assertCount(1, $this->notifier->requests);
    }

    public function testFiveRetriesWithExponentialBackoffThenFailedTransport(): void
    {
        $strategy = self::getContainer()->get('messenger.retry.multiplier_retry_strategy.async');
        self::assertInstanceOf(RetryStrategyInterface::class, $strategy);
        $error = new \RuntimeException('Notifier unavailable');

        foreach ([0 => 1000, 1 => 2000, 2 => 4000, 3 => 8000, 4 => 16000] as $retryCount => $delay) {
            $envelope = new Envelope(new ProcessPaymentEvent(self::INTENT_ID), $retryCount > 0 ? [new RedeliveryStamp($retryCount)] : []);
            self::assertTrue($strategy->isRetryable($envelope, $error), "Retry #{$retryCount} must be allowed.");
            $wait = $strategy->getWaitingTime($envelope, $error);
            self::assertGreaterThanOrEqual((int) ($delay * 0.9), $wait);
            self::assertLessThanOrEqual((int) ($delay * 1.1), $wait);
        }
        self::assertFalse($strategy->isRetryable(new Envelope(new ProcessPaymentEvent(self::INTENT_ID), [new RedeliveryStamp(5)]), $error));

        self::assertInstanceOf(TransportInterface::class, self::getContainer()->get('messenger.transport.failed'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ingest(array $payload): void
    {
        $ingestor = self::getContainer()->get(WebhookIngestor::class);
        self::assertInstanceOf(WebhookIngestor::class, $ingestor);
        self::assertIsString($payload['event_id']);
        self::assertIsString($payload['event']);

        $ingestor->ingest($payload['event_id'], $payload['event'], 'txn_8a7b6c5d4e3f', $payload);
    }

    private function handle(string $eventId): void
    {
        $handler = self::getContainer()->get(ProcessPaymentEventHandler::class);
        self::assertInstanceOf(ProcessPaymentEventHandler::class, $handler);

        $handler(new ProcessPaymentEvent($eventId));
    }

    private function assertRetryableFailure(string $eventId): void
    {
        $failure = null;
        try {
            $this->handle($eventId);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        self::assertNotNull($failure, 'The attempt must fail.');
        self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $failure, 'The failure must be retryable.');
        self::assertNotSame('notified', $this->eventStatus($eventId));
    }

    /**
     * Single worker: processes queued messages in order, including ones queued while processing.
     */
    private function drainQueue(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        while ([] !== $envelopes = [...$transport->get()]) {
            foreach ($envelopes as $envelope) {
                $message = $envelope->getMessage();
                self::assertInstanceOf(ProcessPaymentEvent::class, $message);
                $this->handle($message->eventId);
                $transport->ack($envelope);
            }
        }
    }

    private function eventStatus(string $eventId): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM payment_event WHERE event_id = ?', [$eventId]);
    }

    /**
     * @return list<mixed>
     */
    private function notifiedEventIds(): array
    {
        return array_map(
            static fn (array $request): mixed => json_decode($request['body'], true, flags: \JSON_THROW_ON_ERROR)['event_id'] ?? null,
            $this->notifier->requests,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationFor(string $eventId): array
    {
        foreach ($this->notifier->requests as $request) {
            $notification = json_decode($request['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($notification);
            if (($notification['event_id'] ?? null) === $eventId) {
                /* @var array<string, mixed> $notification */
                return $notification;
            }
        }
        self::fail("No notification sent for {$eventId}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(): array
    {
        return $this->fixture('charge-successful.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function intent(): array
    {
        return $this->fixture('payment-intent-created.json');
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/'.$name);
        self::assertIsString($json);
        $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }
}
