<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\CreateTestCasesService;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\QalityClient;
use Threadable\QalityPlus\Tests\TestCase;

final class CreateTestCasesServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_creates_missing_cases_persists_keys_and_links_them(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
            'link_direction' => 'test_to_requirement',
        ]);

        $summary = $service->create([
            [
                'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
                'qality' => null,
            ],
            [
                'test' => ['id' => 'ExistingTest::test_existing', 'name' => 'test_existing'],
                'qality' => ['issue_key' => 'QA-999'],
            ],
        ], 'PROJ-123', $path);

        self::assertSame(1, $summary->eligible);
        self::assertSame(1, $summary->created);
        self::assertSame(1, $summary->linked);
        self::assertSame([
            'projectId' => '20001',
            'testCases' => [['name' => 'test_checkout', 'testSteps' => []]],
        ], $qality->importPayload);
        self::assertSame([['QA-123', 'PROJ-123', 'Tests', 'test_to_requirement']], $jira->createdLinks);
        self::assertSame(['issue_key' => 'QA-123'], json_decode((string) file_get_contents($path), true)['CheckoutTest::test_checkout']);
    }

    public function test_it_skips_cases_already_persisted_in_the_mapping_file(): void
    {
        $path = $this->temporaryMappingPath();
        file_put_contents($path, json_encode([
            'CheckoutTest::test_checkout' => ['issue_key' => 'QA-123'],
        ], JSON_THROW_ON_ERROR));
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
        ]);

        $summary = $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ]], 'PROJ-123', $path);

        self::assertSame(0, $summary->eligible);
        self::assertSame(1, $summary->skipped);
        self::assertSame([], $qality->importPayload);
        self::assertSame([], $jira->createdLinks);
    }

    public function test_dry_run_does_not_require_api_configuration_or_call_upstreams(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, []);

        $summary = $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout'],
            'qality' => null,
        ]], 'PROJ-123', $path, true);

        self::assertTrue($summary->dryRun);
        self::assertSame(1, $summary->eligible);
        self::assertSame([], $qality->importPayload);
        self::assertFalse(is_file($path));
    }

    private function temporaryMappingPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-map-'.bin2hex(random_bytes(6)).'.json';
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

final class CreateFakeQalityClient implements QalityClient
{
    /** @var array<string, mixed> */
    public array $importPayload = [];

    public function importTestCases(string $projectId, array $testCases): array
    {
        $this->importPayload = [
            'projectId' => $projectId,
            'testCases' => $testCases,
        ];

        return [
            'success' => array_map(static fn (array $testCase): array => [
                'testCaseKey' => 'QA-123',
                'name' => $testCase['name'],
            ], $testCases),
            'errors' => [],
        ];
    }

    public function createTestCycle(string $name, string $projectId, ?string $comment = null): array
    {
        return [];
    }

    public function addTestCasesToCycle(string $cycleId, array $testCaseIds): array
    {
        return [];
    }

    public function listStatuses(): array
    {
        return [];
    }

    public function updateTestExecution(string $executionId, array $fields): array
    {
        return [];
    }

    public function createTestExecution(array $payload): array
    {
        return [];
    }
}

final class CreateFakeJiraClient implements JiraClient
{
    /** @var list<list<string>> */
    public array $createdLinks = [];

    public function issue(string $issueKey): array
    {
        return ['id' => '10001', 'key' => $issueKey];
    }

    public function issueLinkExists(string $testIssueKey, string $requirementIssueKey, string $linkType): bool
    {
        return false;
    }

    public function createIssueLink(
        string $testIssueKey,
        string $requirementIssueKey,
        string $linkType,
        string $direction = 'test_to_requirement',
    ): void {
        $this->createdLinks[] = [$testIssueKey, $requirementIssueKey, $linkType, $direction];
    }
}
