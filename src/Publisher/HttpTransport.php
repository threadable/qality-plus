<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

abstract class HttpTransport
{
    public function __construct(
        protected readonly string $baseUrl,
        protected readonly int $timeout = 300,
        protected readonly int $retries = 0,
        protected readonly int $retryBackoffMs = 250,
        protected readonly string $upstream = 'upstream',
    ) {}

    /**
     * @param  callable(): PendingRequest  $request
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function send(callable $request, string $method, string $uri, array $options = []): array
    {
        $attempt = 0;
        $maxAttempts = $this->retries + 1;

        do {
            $attemptStartedAt = microtime(true);

            try {
                $response = $request()->send(
                    $method,
                    rtrim($this->baseUrl, '/').'/'.ltrim($uri, '/'),
                    $options,
                );
            } catch (ConnectionException $exception) {
                if ($attempt >= $this->retries) {
                    $this->logFailure('upstream connection failed', $method, $uri, $attempt + 1, $maxAttempts, [
                        'failure_type' => $this->connectionFailureType($exception),
                        'status' => null,
                        'status_code' => null,
                        'response_received' => false,
                        'duration_ms' => $this->durationMs($attemptStartedAt),
                        'timeout_seconds' => $this->timeout,
                        'exception' => $exception::class,
                        'error' => $this->safeException($exception),
                    ]);

                    throw new PublisherException(sprintf(
                        'Unable to connect to %s while calling %s %s after %d attempt(s): %s; no HTTP response was received.',
                        $this->upstream,
                        strtoupper($method),
                        $uri,
                        $attempt + 1,
                        $this->connectionFailureReason($exception),
                    ), previous: $exception);
                }

                $retryInMs = $this->backoff($attempt++);
                Log::warning('qality-plus upstream connection failed; retrying', $this->context(
                    $method,
                    $uri,
                    $attempt,
                    $maxAttempts,
                    [
                        'failure_type' => $this->connectionFailureType($exception),
                        'status' => null,
                        'status_code' => null,
                        'response_received' => false,
                        'duration_ms' => $this->durationMs($attemptStartedAt),
                        'timeout_seconds' => $this->timeout,
                        'retry_in_ms' => $retryInMs,
                        'exception' => $exception::class,
                        'error' => $this->safeException($exception),
                    ],
                ));

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
                $retryInMs = $this->backoff($attempt++);
                Log::warning('qality-plus upstream response was retryable; retrying', $this->context(
                    $method,
                    $uri,
                    $attempt,
                    $maxAttempts,
                    [
                        'failure_type' => $response->status() === 429 ? 'rate_limited' : 'retryable_http',
                        'status' => $response->status(),
                        'status_code' => $response->status(),
                        'response_received' => true,
                        'duration_ms' => $this->durationMs($attemptStartedAt),
                        'retry_after' => $response->header('Retry-After'),
                        'retry_in_ms' => $retryInMs,
                    ],
                ));

                continue;
            }

            $error = $this->safeError($response);
            $this->logFailure('upstream request failed', $method, $uri, $attempt + 1, $maxAttempts, [
                'failure_type' => 'http_response',
                'status' => $response->status(),
                'status_code' => $response->status(),
                'response_received' => true,
                'duration_ms' => $this->durationMs($attemptStartedAt),
                'retry_after' => $response->header('Retry-After'),
                'error' => $error,
            ]);

            throw new PublisherException(sprintf(
                '%s returned HTTP %d for %s %s after %d attempt(s): %s',
                $this->upstream,
                $response->status(),
                strtoupper($method),
                $uri,
                $attempt + 1,
                $error,
            ));
        } while (true);
    }

    private function retryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->retryBackoffMs * (2 ** $attempt);

        if ($delay > 0) {
            usleep($delay * 1000);
        }

        return $delay;
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

        $body = trim($response->body());

        return $body !== '' ? substr($body, 0, 1000) : 'no response body';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(string $method, string $uri, int $attempt, int $maxAttempts, array $extra = []): array
    {
        return array_merge([
            'upstream' => $this->upstream,
            'base_host' => parse_url($this->baseUrl, PHP_URL_HOST) ?: 'invalid URL',
            'method' => strtoupper($method),
            'uri' => $uri,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logFailure(string $message, string $method, string $uri, int $attempt, int $maxAttempts, array $extra = []): void
    {
        Log::error('qality-plus '.$message, $this->context($method, $uri, $attempt, $maxAttempts, $extra));
    }

    private function safeException(ConnectionException $exception): string
    {
        return substr(trim($exception->getMessage()), 0, 1000);
    }

    private function connectionFailureType(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'curl error 28')) {
            return 'timeout';
        }

        if (str_contains($message, 'could not resolve') || str_contains($message, 'name or service not known')) {
            return 'dns';
        }

        return 'connection';
    }

    private function connectionFailureReason(ConnectionException $exception): string
    {
        return match ($this->connectionFailureType($exception)) {
            'timeout' => 'request timed out',
            'dns' => 'DNS lookup failed',
            default => 'connection failed',
        };
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
