<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class PaymentWebhookTransaction
{
    #[Assert\NotBlank]
    public ?string $id = null;

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?PaymentWebhookAmount $amount = null;
}
