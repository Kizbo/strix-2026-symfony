<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PaymentWebhookAmount
{
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $value = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Use an ISO 4217 currency code.')]
    public ?string $currency = null;
}
