<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Threadable\QalityPlus\Publisher\HttpJiraClient;
use Threadable\QalityPlus\Publisher\HttpQalityClient;
use Threadable\QalityPlus\Publisher\PublisherException;
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
            'https://qality.test/api/testCases/import' => Http::response([
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
            return $request->url() === 'https://qality.test/api/testCases/import'
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

    public function test_jira_adds_a_label_to_an_issue_without_replacing_existing_labels(): void
    {
        Http::fake([
            'https://jira.test/rest/api/3/issue/QA-123' => Http::response([], 204),
        ]);

        $client = new HttpJiraClient(
            baseUrl: 'https://jira.test',
            email: 'ci@example.com',
            apiToken: 'jira-token',
            bearerToken: null,
        );
        $client->addIssueLabel('QA-123', 'threadable-qality-plus');

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'PUT'
                && $request->url() === 'https://jira.test/rest/api/3/issue/QA-123'
                && $request->data() === [
                    'update' => [
                        'labels' => [['add' => 'threadable-qality-plus']],
                    ],
                ];
        });
    }

    public function test_jira_finds_a_qality_test_case_by_exact_name_within_project_and_issue_type(): void
    {
        Http::fake([
            'https://jira.test/rest/api/3/search/jql' => Http::response([
                'issues' => [
                    ['key' => 'QA-123', 'fields' => ['summary' => 'Customer can complete checkout']],
                    ['key' => 'QA-999', 'fields' => ['summary' => 'Customer can complete checkout later']],
                ],
            ]),
        ]);

        $client = new HttpJiraClient(
            baseUrl: 'https://jira.test',
            email: 'ci@example.com',
            apiToken: 'jira-token',
            bearerToken: null,
        );

        self::assertSame(
            ['QA-123'],
            $client->findTestCaseKeysByName('Customer can complete checkout', 'QA', 'QAlity Test'),
        );

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'https://jira.test/rest/api/3/search/jql'
                && $request->data() === [
                    'jql' => 'project = "QA" AND issuetype = "QAlity Test" AND summary ~ "Customer can complete checkout"',
                    'fields' => ['summary'],
                    'maxResults' => 50,
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

    public function test_qality_adds_cases_to_a_cycle_and_updates_an_execution(): void
    {
        Http::fake([
            'https://qality.test/api/testCycles/cycle-1/testCycleAssignments' => Http::response([
                [
                    'id' => 'cycle-case-1',
                    'testCaseId' => 10001,
                    'testExecution' => ['id' => 'execution-1'],
                ],
            ]),
            'https://qality.test/api/statuses' => Http::response([
                'statuses' => [
                    ['id' => 1, 'name' => 'Passed', 'category' => 'PASSED'],
                ],
            ]),
            'https://qality.test/api/testExecutions/execution-1' => Http::response(['id' => 'execution-1']),
        ]);

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token');

        self::assertSame(
            [[
                'id' => 'cycle-case-1',
                'testCaseId' => 10001,
                'testExecution' => ['id' => 'execution-1'],
            ]],
            $client->addTestCasesToCycle('cycle-1', ['10001']),
        );
        self::assertSame(
            ['statuses' => [['id' => 1, 'name' => 'Passed', 'category' => 'PASSED']]],
            $client->listStatuses(),
        );
        self::assertSame(
            ['id' => 'execution-1'],
            $client->updateTestExecution('execution-1', ['statusId' => 1]),
        );

        Http::assertSent(static fn ($request): bool => $request->url() === 'https://qality.test/api/testCycles/cycle-1/testCycleAssignments'
            && $request->data() === [
                'testCasesIds' => [10001],
            ]);
        Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://qality.test/api/testExecutions/execution-1'
            && $request->data() === ['fields' => ['statusId' => 1]]);
    }

    public function test_qality_fails_when_the_token_is_missing(): void
    {
        $this->expectException(PublisherException::class);

        (new HttpQalityClient('https://qality.test/api', ''))->createTestCycle('CI run', '20001');
    }

    public function test_jira_fails_when_credentials_are_missing(): void
    {
        $this->expectException(PublisherException::class);

        (new HttpJiraClient('https://jira.test', null, null, null))->issue('QA-123');
    }

    public function test_non_retryable_http_failures_are_not_retried(): void
    {
        Log::spy();
        Http::fake([
            'https://qality.test/api/testCycles' => Http::response(['error' => 'invalid project'], 422),
        ]);

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token', retries: 3, retryBackoffMs: 0);

        try {
            $client->createTestCycle('CI run', '20001');
            self::fail('Expected a PublisherException.');
        } catch (PublisherException $exception) {
            self::assertSame(
                'QAlity Plus returned HTTP 422 for POST /testCycles after 1 attempt(s): invalid project',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'qality-plus upstream request failed'
                && $context['upstream'] === 'QAlity Plus'
                && $context['method'] === 'POST'
                && $context['uri'] === '/testCycles'
                && $context['attempt'] === 1
                && $context['max_attempts'] === 4
                && $context['status'] === 422;
        });
    }

    public function test_exhausted_transient_failures_raise_an_exception(): void
    {
        Log::spy();
        Http::fakeSequence()
            ->push(['error' => 'temporary'], 503)
            ->push(['error' => 'temporary'], 503);

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token', retries: 1, retryBackoffMs: 0);

        try {
            $client->createTestCycle('CI run', '20001');
            self::fail('Expected a PublisherException.');
        } catch (PublisherException $exception) {
            self::assertSame(
                'QAlity Plus returned HTTP 503 for POST /testCycles after 2 attempt(s): temporary',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'qality-plus upstream response was retryable; retrying'
                && $context['upstream'] === 'QAlity Plus'
                && $context['attempt'] === 1
                && $context['max_attempts'] === 2
                && $context['status'] === 503
                && $context['retry_in_ms'] === 0;
        });
        Log::shouldHaveReceived('error')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'qality-plus upstream request failed'
                && $context['attempt'] === 2
                && $context['max_attempts'] === 2
                && $context['status'] === 503;
        });
    }

    public function test_connection_failures_include_upstream_and_attempt_details(): void
    {
        Log::spy();
        Http::fake(static function (): never {
            throw new ConnectionException('Could not resolve host: qality.test');
        });

        $client = new HttpQalityClient('https://qality.test/api', 'qality-token', retries: 1, retryBackoffMs: 0);

        try {
            $client->createTestCycle('CI run', '20001');
            self::fail('Expected a PublisherException.');
        } catch (PublisherException $exception) {
            self::assertSame(
                'Unable to connect to QAlity Plus while calling POST /testCycles after 2 attempt(s).',
                $exception->getMessage(),
            );
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'qality-plus upstream connection failed; retrying'
                && $context['upstream'] === 'QAlity Plus'
                && $context['attempt'] === 1
                && $context['max_attempts'] === 2
                && $context['retry_in_ms'] === 0
                && $context['exception'] === ConnectionException::class;
        });
        Log::shouldHaveReceived('error')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'qality-plus upstream connection failed'
                && $context['upstream'] === 'QAlity Plus'
                && $context['method'] === 'POST'
                && $context['uri'] === '/testCycles'
                && $context['attempt'] === 2
                && $context['max_attempts'] === 2
                && $context['exception'] === ConnectionException::class;
        });
    }

    public function test_jira_rejects_an_invalid_link_direction(): void
    {
        $client = new HttpJiraClient(
            baseUrl: 'https://jira.test',
            email: 'ci@example.com',
            apiToken: 'jira-token',
            bearerToken: null,
        );

        $this->expectException(PublisherException::class);
        $client->createIssueLink('QA-123', 'REQ-42', 'Tests', 'sideways');
    }
}
