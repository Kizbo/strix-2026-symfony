<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PaymentEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends one event to the sales team's notifier API. Throws a retryable exception
 * on 5xx/timeouts and an unrecoverable one when the notifier rejects the request (4xx).
 */
final readonly class PaymentNotifier
{
    private const array RETRYABLE_CLIENT_ERRORS = [Response::HTTP_REQUEST_TIMEOUT, Response::HTTP_TOO_MANY_REQUESTS];

    public function __construct(
        private HttpClientInterface $notifierClient,
    ) {
    }

    public function send(PaymentEvent $event): void
    {
        $response = $this->notifierClient->request('POST', '/notifications', ['json' => $this->notification($event)]);
        $status = $response->getStatusCode();

        if ($status >= 400 && $status < 500 && !\in_array($status, self::RETRYABLE_CLIENT_ERRORS, true)) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Notifier rejected event %s with HTTP %d.', $event->getEventId(), $status));
        }
        if ($status >= 300) {
            throw new \RuntimeException(\sprintf('Notifier answered HTTP %d for event %s.', $status, $event->getEventId()));
        }
    }

    /**
     * Customer e-mail and name are deliberately left out.
     *
     * @return array<string, mixed>
     */
    private function notification(PaymentEvent $event): array
    {
        $payload = $event->getPayload();

        return [
            'event_id' => $event->getEventId(),
            'event' => $event->getEventType(),
            'livemode' => $payload['livemode'] ?? null,
            'transaction_id' => $event->getTransactionId(),
            'order_id' => $payload['data']['metadata']['order_id'] ?? null,
            'amount' => [
                'value' => $payload['data']['transaction']['amount']['value'] ?? null,
                'currency' => $payload['data']['transaction']['amount']['currency'] ?? null,
            ],
            'customer_company' => $payload['data']['customer']['billing_address']['company'] ?? null,
            'received_at' => $event->getReceivedAt()->format(\DATE_ATOM),
        ];
    }
}
