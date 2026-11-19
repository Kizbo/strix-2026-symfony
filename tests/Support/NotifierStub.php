<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the external notifier API: answers with queued responses and records requests.
 */
final class NotifierStub
{
    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];

    public function respondWith(MockResponse ...$responses): void
    {
        array_push($this->responses, ...$responses);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $body = $options['body'] ?? '';
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($body) ? $body : ''];

        return array_shift($this->responses) ?? throw new \LogicException('Unexpected notifier request.');
    }
}
