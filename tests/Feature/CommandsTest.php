<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Threadable\QalityPlus\Tests\TestCase;

final class CommandsTest extends TestCase
{
    private string $resultPath;

    private string $mappingPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resultPath = tempnam(sys_get_temp_dir(), 'qality-results-');
        $this->mappingPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-map-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->resultPath)) {
            unlink($this->resultPath);
        }

        if (is_file($this->mappingPath)) {
            unlink($this->mappingPath);
        }

        parent::tearDown();
    }

    public function test_create_command_can_validate_results_through_the_testbench_application(): void
    {
        $this->writeResult('CheckoutTest::test_checkout', 'test_checkout');
        config(['qality.results.directory' => $this->resultPath]);

        $this->artisan('qality:create-test-cases', [
            '--branch' => 'feature/PROJ-123-checkout',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Validated 1 eligible test case(s) for PROJ-123')
            ->assertExitCode(0);
    }

    public function test_create_command_imports_and_links_a_missing_case(): void
    {
        $this->writeResult('CheckoutTest::test_checkout', 'test_checkout');
        config([
            'qality.results.directory' => $this->resultPath,
            'qality.qality.base_url' => 'https://qality.test/api',
            'qality.qality.token' => 'qality-token',
            'qality.qality.project_id' => '20001',
            'qality.jira.base_url' => 'https://jira.test',
            'qality.jira.email' => 'ci@example.com',
            'qality.jira.api_token' => 'jira-token',
            'qality.publisher.linking.type' => 'Tests',
        ]);
        Http::fake([
            'https://qality.test/api/testCases/import' => Http::response([
                'success' => [['testCaseKey' => 'QA-123', 'name' => 'test_checkout']],
                'errors' => [],
            ]),
            'https://jira.test/rest/api/3/issue/*' => Http::response([
                'fields' => ['issuelinks' => []],
            ]),
            'https://jira.test/rest/api/3/issueLink' => Http::response([], 201),
        ]);

        $this->artisan('qality:create-test-cases', [
            '--branch' => 'feature/PROJ-123-checkout',
            '--mapping-file' => $this->mappingPath,
        ])
            ->expectsOutputToContain('Processed 1 eligible test case(s) for PROJ-123; 1 created, 1 linked, 0 skipped.')
            ->assertExitCode(0);

        Http::assertSentCount(3);
        self::assertSame('QA-123', json_decode((string) file_get_contents($this->mappingPath), true)['CheckoutTest::test_checkout']['issue_key']);
    }

    public function test_create_command_rejects_an_invalid_branch_before_api_calls(): void
    {
        $this->writeResult('CheckoutTest::test_checkout', 'test_checkout');
        Http::fake();

        $this->artisan('qality:create-test-cases', [
            'path' => $this->resultPath,
            '--branch' => 'chore/update-dependencies',
            '--mapping-file' => $this->mappingPath,
        ])
            ->expectsOutputToContain('does not match the configured feature/hotfix/bugfix work-item pattern')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_publish_command_creates_a_cycle_execution_and_requirement_link(): void
    {
        $this->writeResult('CheckoutTest::test_checkout', 'test_checkout', [
            'issue_key' => 'QA-123',
            'requirement_issue_key' => 'PROJ-123',
        ]);
        config([
            'qality.results.directory' => $this->resultPath,
            'qality.qality.base_url' => 'https://qality.test/api',
            'qality.qality.token' => 'qality-token',
            'qality.qality.project_id' => '20001',
            'qality.jira.base_url' => 'https://jira.test',
            'qality.jira.email' => 'ci@example.com',
            'qality.jira.api_token' => 'jira-token',
            'qality.publisher.linking.enabled' => true,
            'qality.publisher.linking.type' => 'Tests',
        ]);
        Http::fake([
            'https://qality.test/api/testCycles' => Http::response(['id' => 'cycle-1'], 201),
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
                    ['id' => 2, 'name' => 'Failed', 'category' => 'FAILED'],
                    ['id' => 3, 'name' => 'Unexecuted', 'category' => 'UNFINISHED'],
                ],
            ]),
            'https://qality.test/api/testExecutions/execution-1' => Http::response(['id' => 'execution-1']),
            'https://jira.test/rest/api/3/issue/*' => Http::response([
                'id' => '10001',
                'fields' => ['issuelinks' => []],
            ]),
            'https://jira.test/rest/api/3/issueLink' => Http::response([], 201),
        ]);

        $this->artisan('qality:publish')
            ->expectsOutputToContain('Published 1 result(s); 1 published, 0 skipped, 1 Jira link(s) in cycle cycle-1.')
            ->assertExitCode(0);

        Http::assertSentCount(7);
    }

    public function test_publish_command_resolves_a_missing_case_by_name(): void
    {
        $this->writeResult('CheckoutTest::test_checkout', 'test_checkout', ['name' => 'test_checkout']);
        config([
            'qality.results.directory' => $this->resultPath,
            'qality.qality.base_url' => 'https://qality.test/api',
            'qality.qality.token' => 'qality-token',
            'qality.qality.project_id' => '20001',
            'qality.jira.base_url' => 'https://jira.test',
            'qality.jira.email' => 'ci@example.com',
            'qality.jira.api_token' => 'jira-token',
            'qality.jira.project_key' => 'QA',
        ]);
        Http::fake([
            'https://jira.test/rest/api/3/search/jql' => Http::response([
                'issues' => [['key' => 'QA-123', 'fields' => ['summary' => 'test_checkout']]],
            ]),
            'https://qality.test/api/statuses' => Http::response([
                'statuses' => [
                    ['id' => 1, 'name' => 'Passed', 'category' => 'PASSED'],
                    ['id' => 2, 'name' => 'Failed', 'category' => 'FAILED'],
                    ['id' => 3, 'name' => 'Unexecuted', 'category' => 'UNFINISHED'],
                ],
            ]),
            'https://qality.test/api/testCycles' => Http::response(['id' => 'cycle-1'], 201),
            'https://jira.test/rest/api/3/issue/*' => Http::response([
                'id' => '10001',
                'fields' => ['issuelinks' => []],
            ]),
            'https://qality.test/api/testCycles/cycle-1/testCycleAssignments' => Http::response([
                ['testCaseId' => 10001, 'testExecution' => ['id' => 'execution-1']],
            ]),
            'https://qality.test/api/testExecutions/execution-1' => Http::response(['id' => 'execution-1']),
        ]);

        $this->artisan('qality:publish')
            ->expectsOutputToContain('Published 1 result(s); 1 published, 0 skipped, 0 Jira link(s) in cycle cycle-1.')
            ->assertExitCode(0);

        Http::assertSentCount(6);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function writeResult(string $id, string $name, ?array $metadata = null): void
    {
        file_put_contents($this->resultPath, json_encode([
            'schema_version' => 1,
            'run_id' => 'run-1',
            'sequence' => 1,
            'recorded_at' => '2026-08-07T12:00:00+00:00',
            'status' => 'passed',
            'test' => ['id' => $id, 'name' => $name],
            'qality' => $metadata,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
