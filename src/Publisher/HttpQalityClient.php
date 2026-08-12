<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpQalityClient extends HttpTransport implements QalityClient
{
    public function __construct(
        string $baseUrl,
        private readonly string $token,
        int $timeout = 120,
        int $retries = 0,
        int $retryBackoffMs = 250,
    ) {
        parent::__construct($baseUrl, $timeout, $retries, $retryBackoffMs, 'QAlity Plus');
    }

    /**
     * @param  list<array<string, mixed>>  $testCases
     * @return array<string, mixed>
     */
    public function importTestCases(string $projectId, array $testCases): array
    {
        return $this->sendQality('POST', '/testCases/import', [
            'projectId' => $projectId,
            'testCases' => array_values($testCases),
        ]);
    }

    public function createTestCycle(string $name, string $projectId, ?string $comment = null): array
    {
        $payload = [
            'name' => $name,
            'projectId' => $projectId,
        ];

        if ($comment !== null && $comment !== '') {
            $payload['comment'] = $comment;
        }

        return $this->sendQality('POST', '/testCycles', $payload);
    }

    public function addTestCasesToCycle(string $cycleId, array $testCaseIds): array
    {
        $payload = $this->sendQality('POST', "/testCycles/{$cycleId}/testCycleAssignments", [
            'testCasesIds' => array_values(array_map('intval', $testCaseIds)),
        ]);

        return $this->listPayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function listStatuses(): array
    {
        return $this->send(
            fn (): PendingRequest => $this->request(),
            'GET',
            '/statuses',
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateTestExecution(string $executionId, array $fields): array
    {
        return $this->sendQality('PATCH', "/testExecutions/{$executionId}", [
            'fields' => $fields,
        ]);
    }

    public function createTestExecution(array $payload): array
    {
        return $this->sendQality('POST', '/testExecutions', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendQality(string $method, string $uri, array $payload): array
    {
        return $this->send(
            fn (): PendingRequest => $this->request(),
            $method,
            $uri,
            ['json' => $payload],
        );
    }

    private function request(): PendingRequest
    {
        if ($this->token === '') {
            throw new PublisherException('QAlity Plus API token is not configured.');
        }

        return Http::acceptJson()
            ->asJson()
            ->withToken($this->token)
            ->timeout($this->timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function listPayload(array $payload): array
    {
        foreach (['data', 'content', 'testCasesInCycle', 'values'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return array_is_list($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }
}
