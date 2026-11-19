<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Required part of the webhook envelope. Property names match the JSON keys so
 * violation paths are reported as the client sent them. Properties are set one
 * by one (no constructor) so a value of the wrong type is rejected, not coerced.
 */
final class PaymentWebhookInput
{
    #[Assert\NotBlank]
    public ?string $event = null;

    #[Assert\NotBlank]
    public ?string $event_id = null;

    #[Assert\NotNull]
    public ?int $timestamp = null;

    #[Assert\NotNull]
    public ?bool $livemode = null;

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?PaymentWebhookData $data = null;
}
