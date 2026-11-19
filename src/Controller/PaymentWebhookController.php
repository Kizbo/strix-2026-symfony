<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PaymentWebhookAccepted;
use App\Dto\PaymentWebhookInput;
use App\Service\WebhookIngestor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentWebhookController extends AbstractController
{
    public const string ROUTE = 'api_payment_webhook';

    public function __construct(
        private readonly WebhookIngestor $ingestor,
    ) {
    }

    #[Route('/api/v1/webhooks/payment', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload(acceptFormat: 'json')] PaymentWebhookInput $input,
        Request $request,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();

        $this->ingestor->ingest($input->eventId(), (string) $input->event, $input->transactionId(), $payload);

        return $this->json(new PaymentWebhookAccepted($input->eventId()), Response::HTTP_ACCEPTED);
    }
}
