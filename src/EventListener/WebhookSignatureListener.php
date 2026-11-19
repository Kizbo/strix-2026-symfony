<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\PaymentWebhookController;
use App\Service\WebhookSignatureVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs on kernel.request, so the signature is verified before the payload is mapped and validated.
 */
#[AsEventListener]
final readonly class WebhookSignatureListener
{
    public const string HEADER = 'X-Webhook-Signature';

    public function __construct(
        private WebhookSignatureVerifier $verifier,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (PaymentWebhookController::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        if (!$this->verifier->isValid($request->getContent(), $request->headers->get(self::HEADER))) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED);
        }
    }
}
