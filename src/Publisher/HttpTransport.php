<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

abstract class HttpTransport
{
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly int $timeout = 30,
        protected readonly int $retries = 2,
        protected readonly int $retryBackoffMs = 250,
    ) {}

    /**
     * @param  callable(): PendingRequest  $request
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function send(callable $request, string $method, string $uri, array $options = []): array
    {
        $attempt = 0;

        do {
            try {
                $response = $request()->send(
                    $method,
                    rtrim($this->baseUrl, '/').'/'.ltrim($uri, '/'),
                    $options,
                );
            } catch (ConnectionException $exception) {
                if ($attempt >= $this->retries) {
                    throw new PublisherException('Unable to connect to an upstream API.', previous: $exception);
                }

                $this->backoff($attempt++);

                continue;
            }

            if ($response->successful()) {
                if ($response->body() === '') {
                    return [];
                }

                $payload = $response->json();

                return is_array($payload) ? $payload : [];
            }

            if ($this->retryable($response) && $attempt < $this->retries) {
                $this->backoff($attempt++);

                continue;
            }

            throw new PublisherException(sprintf(
                'Upstream API returned HTTP %d for %s %s: %s',
                $response->status(),
                strtoupper($method),
                $uri,
                $this->safeError($response),
            ));
        } while (true);
    }

    private function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function backoff(int $attempt): void
    {
        $delay = $this->retryBackoffMs * (2 ** $attempt);

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function safeError(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $message = $json['message'] ?? $json['error'] ?? null;

            if (is_string($message)) {
                return $message;
            }
        }

        return trim($response->body()) !== '' ? trim($response->body()) : 'no response body';
    }
}
