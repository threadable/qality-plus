<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Threadable\QalityPlus\Publisher\HttpJiraClient;
use Threadable\QalityPlus\Publisher\HttpQalityClient;
use Threadable\QalityPlus\Tests\TestCase;

final class HttpClientsTest extends TestCase
{
    public function test_qality_requests_use_the_configured_base_url_and_bearer_token(): void
    {
        Http::fake([
            'https://qality.test/api/testCycles' => Http::response(['id' => 'cycle-1']),
        ]);

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token');
        $cycle = $client->createTestCycle('CI run', '20001');

        self::assertSame('cycle-1', $cycle['id']);
        Http::assertSent(static function ($request): bool {
            return $request->url() === 'https://qality.test/api/testCycles'
                && $request->header('Authorization') === ['Bearer qality-token']
                && $request->data() === ['name' => 'CI run', 'projectId' => '20001'];
        });
    }

    public function test_qality_imports_test_cases_with_the_project_id(): void
    {
        Http::fake([
            'https://qality.test/api/import-test-cases' => Http::response([
                'success' => [['testCaseKey' => 'QA-123', 'name' => 'test_checkout']],
                'errors' => [],
            ], 200),
        ]);

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token');
        $result = $client->importTestCases('20001', [[
            'name' => 'test_checkout',
            'testSteps' => [],
        ]]);

        self::assertSame('QA-123', $result['success'][0]['testCaseKey']);
        Http::assertSent(static function ($request): bool {
            return $request->url() === 'https://qality.test/api/import-test-cases'
                && $request->data() === [
                    'projectId' => '20001',
                    'testCases' => [[
                        'name' => 'test_checkout',
                        'testSteps' => [],
                    ]],
                ];
        });
    }

    public function test_jira_issue_links_use_basic_auth_and_the_documented_payload(): void
    {
        Http::fake([
            'https://jira.test/rest/api/3/issueLink' => Http::response([], 201),
        ]);

        $client = new HttpJiraClient(
            baseUrl: 'https://jira.test',
            email: 'ci@example.com',
            apiToken: 'jira-token',
            bearerToken: null,
        );
        $client->createIssueLink('QA-123', 'REQ-42', 'Tests');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'https://jira.test/rest/api/3/issueLink'
                && $request->header('Authorization') === ['Basic '.base64_encode('ci@example.com:jira-token')]
                && $request->data() === [
                    'type' => ['name' => 'Tests'],
                    'inwardIssue' => ['key' => 'REQ-42'],
                    'outwardIssue' => ['key' => 'QA-123'],
                ];
        });
    }

    public function test_qality_retries_transient_server_errors(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'temporary'], 503)
            ->push(['id' => 'cycle-1'], 201);

        $client = new HttpQalityClient(
            baseUrl: 'https://qality.test/api',
            token: 'qality-token',
            retries: 1,
            retryBackoffMs: 0,
        );

        self::assertSame('cycle-1', $client->createTestCycle('CI run', '20001')['id']);
    }
}
