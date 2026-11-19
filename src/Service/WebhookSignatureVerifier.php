<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class WebhookSignatureVerifier
{
    public function __construct(
        #[Autowire(env: 'WEBHOOK_SECRET')]
        private string $secret,
    ) {
    }

    public function isValid(string $rawBody, ?string $signature): bool
    {
        if (null === $signature || '' === $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret), $signature);
    }
}
