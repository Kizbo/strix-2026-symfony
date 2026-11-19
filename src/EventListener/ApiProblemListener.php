<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders errors under /api/ as RFC 9457 problem+json. Details are fixed texts,
 * never exception messages; the raw body is never logged.
 */
#[AsEventListener]
final readonly class ApiProblemListener
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $previous = $exception->getPrevious();

        $problem = match (true) {
            Response::HTTP_UNAUTHORIZED === $status => [
                'title' => 'Invalid signature',
                'detail' => 'The X-Webhook-Signature header is missing or does not match the request body.',
            ],
            Response::HTTP_BAD_REQUEST === $status && $previous instanceof NotEncodableValueException => [
                'title' => 'Malformed JSON',
                'detail' => 'The request body is not valid JSON.',
            ],
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $status => [
                'title' => 'Unsupported Media Type',
                'detail' => 'Send the request body as application/json.',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY === $status && $previous instanceof ValidationFailedException => [
                'title' => 'Validation failed',
                'detail' => 'The request body has invalid or missing fields.',
                'violations' => array_map(
                    static fn ($violation): array => ['propertyPath' => $violation->getPropertyPath(), 'title' => (string) $violation->getMessage()],
                    iterator_to_array($previous->getViolations()),
                ),
            ],
            default => ['title' => Response::$statusTexts[$status] ?? 'Error'],
        };

        if ($status < Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->logger->warning('API request rejected', [
                'status' => $status,
                'reason' => $problem['title'],
                'route' => $request->attributes->get('_route'),
                'event_id' => $this->eventId($request),
            ]);
        }

        $event->setResponse(new JsonResponse(
            ['type' => 'about:blank', 'status' => $status] + $problem,
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function eventId(Request $request): ?string
    {
        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) && \is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null;
    }
}
