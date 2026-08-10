<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Threadable\QalityPlus\Publisher\HttpJiraClient;
use Threadable\QalityPlus\Publisher\HttpQalityClient;
use Threadable\QalityPlus\Tests\Support\JsonSchemaValidator;
use Threadable\QalityPlus\Tests\TestCase;

final class UpstreamContractsTest extends TestCase
{
    public function test_contract_fixtures_match_the_upstream_schemas(): void
    {
        $contracts = [
            ['payloads/qality-import-request.json', 'schemas/qality-import-request.json'],
            ['responses/qality-import-response.json', 'schemas/qality-import-response.json'],
            ['payloads/qality-cycle-request.json', 'schemas/qality-cycle-request.json'],
            ['responses/qality-cycle-response.json', 'schemas/qality-cycle-response.json'],
            ['payloads/qality-assignment-request.json', 'schemas/qality-assignment-request.json'],
            ['responses/qality-assignment-response.json', 'schemas/qality-assignment-response.json'],
            ['responses/qality-statuses-response.json', 'schemas/qality-statuses-response.json'],
            ['payloads/qality-execution-patch-request.json', 'schemas/qality-execution-patch-request.json'],
            ['responses/qality-execution-response.json', 'schemas/qality-execution-response.json'],
            ['responses/jira-issue-response.json', 'schemas/jira-issue-response.json'],
            ['payloads/jira-issue-link-request.json', 'schemas/jira-issue-link-request.json'],
        ];

        foreach ($contracts as [$fixture, $schema]) {
            JsonSchemaValidator::assertValid(
                $this->fixture($fixture),
                $this->fixture($schema),
                $fixture,
            );
        }
    }

    public function test_qality_requests_match_contract_payloads_and_responses(): void
    {
        $importResponse = $this->fixture('responses/qality-import-response.json');
        $cycleResponse = $this->fixture('responses/qality-cycle-response.json');
        $assignmentResponse = $this->fixture('responses/qality-assignment-response.json');
        $statusesResponse = $this->fixture('responses/qality-statuses-response.json');
        $executionResponse = $this->fixture('responses/qality-execution-response.json');
        $importRequest = $this->fixture('payloads/qality-import-request.json');
        $cycleRequest = $this->fixture('payloads/qality-cycle-request.json');
        $assignmentRequest = $this->fixture('payloads/qality-assignment-request.json');
        $executionPatchRequest = $this->fixture('payloads/qality-execution-patch-request.json');

        Http::fake([
            'https://qality.contract/api/testCases/import' => Http::response($importResponse),
            'https://qality.contract/api/testCycles' => Http::response($cycleResponse, 201),
            'https://qality.contract/api/testCycles/162395/testCycleAssignments' => Http::response($assignmentResponse),
            'https://qality.contract/api/statuses' => Http::response($statusesResponse),
            'https://qality.contract/api/testExecutions/3338552' => Http::response($executionResponse),
        ]);

        $client = new HttpQalityClient('https://qality.contract/api', 'qality-token');

        self::assertSame(
            $importResponse,
            $client->importTestCases($importRequest['projectId'], $importRequest['testCases']),
        );
        self::assertSame(
            $cycleResponse,
            $client->createTestCycle($cycleRequest['name'], $cycleRequest['projectId'], $cycleRequest['comment']),
        );
        self::assertSame($assignmentResponse, $client->addTestCasesToCycle('162395', $assignmentRequest['testCasesIds']));
        self::assertSame($statusesResponse, $client->listStatuses());
        self::assertSame(
            $executionResponse,
            $client->updateTestExecution('3338552', $executionPatchRequest['fields']),
        );

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.contract/api/testCases/import'
            && $request->method() === 'POST'
            && $request->data() === $importRequest);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.contract/api/testCycles'
            && $request->method() === 'POST'
            && $request->data() === $cycleRequest);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.contract/api/testCycles/162395/testCycleAssignments'
            && $request->method() === 'POST'
            && $request->data() === $assignmentRequest);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.contract/api/statuses'
            && $request->method() === 'GET');
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.contract/api/testExecutions/3338552'
            && $request->method() === 'PATCH'
            && $request->data() === $executionPatchRequest);
    }

    public function test_jira_requests_match_contract_payloads_and_responses(): void
    {
        $issueResponse = $this->fixture('responses/jira-issue-response.json');
        $linkRequest = $this->fixture('payloads/jira-issue-link-request.json');

        Http::fake([
            'https://jira.contract/rest/api/3/issue/*' => Http::response($issueResponse),
            'https://jira.contract/rest/api/3/issueLink' => Http::response([], 201),
        ]);

        $client = new HttpJiraClient(
            baseUrl: 'https://jira.contract',
            email: 'ci@example.com',
            apiToken: 'jira-token',
            bearerToken: null,
        );

        self::assertSame($issueResponse, $client->issue('T2M-41'));
        $client->createIssueLink('T2M-41', 'T2M-12', 'QAlity Test');

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://jira.contract/rest/api/3/issue/T2M-41?fields=issuelinks,project,issuetype'
            && $request->method() === 'GET'
            && $request->header('Authorization') === ['Basic '.base64_encode('ci@example.com:jira-token')]);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://jira.contract/rest/api/3/issueLink'
            && $request->method() === 'POST'
            && $request->data() === $linkRequest);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function fixture(string $relativePath): array
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'Contracts'.DIRECTORY_SEPARATOR.$relativePath;

        /** @var array<string, mixed>|list<array<string, mixed>> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
