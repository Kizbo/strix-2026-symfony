<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    private const string BODY = '{"event_id":"evt_1"}';
    private const string VALID_SIGNATURE = 'ce7195ceea59751b3d74ccd31f7e56f2f752f58362deabe772933102b2ede2da';

    public function testAcceptsHmacSha256OfRawBody(): void
    {
        $verifier = new WebhookSignatureVerifier('test_secret');

        self::assertTrue($verifier->isValid(self::BODY, self::VALID_SIGNATURE));
    }

    public function testRejectsMissingSignature(): void
    {
        $verifier = new WebhookSignatureVerifier('test_secret');

        self::assertFalse($verifier->isValid(self::BODY, null));
        self::assertFalse($verifier->isValid(self::BODY, ''));
    }

    public function testRejectsSignatureOfAnotherBody(): void
    {
        $verifier = new WebhookSignatureVerifier('test_secret');

        self::assertFalse($verifier->isValid('{"event_id":"evt_2"}', self::VALID_SIGNATURE));
    }

    public function testRejectsSignatureMadeWithAnotherSecret(): void
    {
        $verifier = new WebhookSignatureVerifier('other_secret');

        self::assertFalse($verifier->isValid(self::BODY, self::VALID_SIGNATURE));
    }
}
