<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PaymentWebhookAccepted
{
    public function __construct(
        public string $event_id,
    ) {
    }
}
