<?php

declare(strict_types=1);

namespace App\Tests\Application;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PaymentWebhookTest extends WebTestCase
{
    private const string URL = '/api/v1/webhooks/payment';
    private const string SECRET = 'test_secret';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->executeStatement('TRUNCATE payment_event');
    }

    public function testAcceptsContractSampleUnchanged(): void
    {
        $body = $this->contractSampleJson();

        $this->post($body, $this->sign($body));

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"event_id":"evt_9f8d7c6b5a4321"}', $this->responseContent());
        self::assertSame(1, $this->countRows());
        self::assertCount(1, $this->transport()->getSent());
    }

    public function testDuplicateDeliveryGetsSameResponseAndIsStoredOnce(): void
    {
        $body = $this->contractSampleJson();

        $this->post($body, $this->sign($body));
        $first = $this->responseContent();
        $this->post($body, $this->sign($body));

        self::assertResponseStatusCodeSame(202);
        self::assertSame($first, $this->responseContent());
        self::assertSame(1, $this->countRows());
        self::assertCount(1, $this->transport()->getSent());
    }

    public function testMissingSignatureIsUnauthorized(): void
    {
        $this->post($this->contractSampleJson(), null);

        $this->assertProblem(401);
        $this->assertNothingStored();
    }

    public function testSignatureOfAnotherBodyIsUnauthorized(): void
    {
        $this->post($this->contractSampleJson(), $this->sign('{"event_id":"evt_other"}'));

        $this->assertProblem(401);
        $this->assertNothingStored();
    }

    public function testSignatureWithWrongSecretIsUnauthorized(): void
    {
        $body = $this->contractSampleJson();

        $this->post($body, hash_hmac('sha256', $body, 'wrong_secret'));

        $this->assertProblem(401);
        $this->assertNothingStored();
    }

    public function testSignatureIsCheckedBeforePayload(): void
    {
        $this->post('{"event":', null);

        $this->assertProblem(401);
    }

    public function testTextPlainIsUnsupportedMediaType(): void
    {
        $body = $this->contractSampleJson();

        $this->post($body, $this->sign($body), 'text/plain');

        $this->assertProblem(415);
        $this->assertNothingStored();
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $body = '{"event":';

        $this->post($body, $this->sign($body));

        $problem = $this->assertProblem(400);
        self::assertSame('Malformed JSON', $problem['title']);
        self::assertIsString($problem['detail'] ?? '');
        self::assertStringNotContainsString('Request payload contains invalid', (string) ($problem['detail'] ?? ''));
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function requiredFields(): iterable
    {
        yield 'event' => [['event']];
        yield 'event_id' => [['event_id']];
        yield 'timestamp' => [['timestamp']];
        yield 'livemode' => [['livemode']];
        yield 'data.transaction.id' => [['data', 'transaction', 'id']];
        yield 'data.transaction.amount.value' => [['data', 'transaction', 'amount', 'value']];
        yield 'data.transaction.amount.currency' => [['data', 'transaction', 'amount', 'currency']];
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('requiredFields')]
    public function testMissingRequiredFieldIsUnprocessable(array $path): void
    {
        $payload = $this->contractSample();
        $this->unsetPath($payload, $path);
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        $this->post($body, $this->sign($body));

        $this->assertViolationAt(implode('.', $path));
        $this->assertNothingStored();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'decimal string value' => ['299.90', 'value'];
        yield 'negative value' => [-1, 'value'];
        yield 'currency symbol' => ['zł', 'currency'];
        yield 'lowercase currency' => ['pln', 'currency'];
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountIsUnprocessable(mixed $value, string $field): void
    {
        $payload = $this->contractSample();
        $this->setPath($payload, ['data', 'transaction', 'amount', $field], $value);
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        $this->post($body, $this->sign($body));

        $this->assertViolationAt('data.transaction.amount.'.$field);
        $this->assertNothingStored();
    }

    private function post(string $body, ?string $signature, string $contentType = 'application/json'): void
    {
        $server = ['CONTENT_TYPE' => $contentType];
        if (null !== $signature) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }

        $this->client->request('POST', self::URL, server: $server, content: $body);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertProblem(int $status): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $problem = json_decode($this->responseContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($status, $problem['status'] ?? null);
        self::assertIsString($problem['title'] ?? null);
        self::assertStringNotContainsString('Exception', $this->responseContent());

        /* @var array<string, mixed> $problem */
        return $problem;
    }

    private function assertViolationAt(string $propertyPath): void
    {
        $problem = $this->assertProblem(422);
        self::assertIsArray($problem['violations'] ?? null);

        $paths = array_map(
            static fn (mixed $violation): mixed => \is_array($violation) ? ($violation['propertyPath'] ?? null) : null,
            $problem['violations'],
        );
        self::assertContains($propertyPath, $paths);
    }

    private function assertNothingStored(): void
    {
        self::assertSame(0, $this->countRows());
        self::assertCount(0, $this->transport()->getSent());
    }

    private function contractSampleJson(): string
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/charge-successful.json');
        self::assertIsString($json);

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function contractSample(): array
    {
        $payload = json_decode($this->contractSampleJson(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     */
    private function unsetPath(array &$payload, array $path): void
    {
        $key = array_shift($path);
        if ([] === $path) {
            unset($payload[$key]);

            return;
        }
        self::assertIsArray($payload[$key]);
        $this->unsetPath($payload[$key], $path);
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $path
     */
    private function setPath(array &$payload, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ([] === $path) {
            $payload[$key] = $value;

            return;
        }
        self::assertIsArray($payload[$key]);
        $this->setPath($payload[$key], $path, $value);
    }

    private function responseContent(): string
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function countRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM payment_event');
    }
}
