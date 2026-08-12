<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\JiraBulkTestCaseLookup;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\JiraTestCaseLookup;
use Threadable\QalityPlus\Publisher\JiraTestCaseResolver;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Publisher\QalityClient;
use Threadable\QalityPlus\Publisher\QalityPublisher;
use Threadable\QalityPlus\Tests\TestCase;

final class QalityPublisherTest extends TestCase
{
    public function test_it_creates_a_cycle_adds_cases_and_publishes_execution(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'cycle_name' => 'CI run',
            'linking' => ['enabled' => false],
        ]);

        $summary = $publisher->publish([[
            'status' => 'failed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => ['issue_key' => 'QA-123'],
            'details' => ['message' => 'Expected failure'],
        ]]);

        self::assertSame('cycle-1', $summary->cycleId);
        self::assertSame(1, $summary->published);
        self::assertSame('execution-0', $qality->executions[0]['executionId']);
        self::assertSame(2, $qality->executions[0]['fields']['statusId']);
        self::assertSame('Expected failure', $qality->executions[0]['fields']['comment']);
        self::assertSame(['10001'], $qality->addedCaseIds);
    }

    public function test_it_skips_unmapped_results_without_calling_apis(): void
    {
        $qality = new FakeQalityClient;
        $summary = (new QalityPublisher($qality, new FakeJiraClient, []))->publish([[
            'status' => 'passed',
            'test' => ['id' => 'Example::test'],
            'qality' => null,
        ]]);

        self::assertSame(1, $summary->skipped);
        self::assertSame(0, $qality->cycleCreates);
    }

    public function test_it_resolves_missing_issue_keys_by_test_name(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $jira->testCaseKeysByName['test_checkout'] = ['QA-456'];
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
            'linking' => ['enabled' => false],
        ]);

        $summary = $publisher->publish([[
            'status' => 'passed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ]]);

        self::assertSame(1, $summary->published);
        self::assertSame(['10001'], $qality->addedCaseIds);
    }

    public function test_it_resolves_missing_issue_keys_with_one_bulk_lookup(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $jira->testCaseKeysByName = [
            'checkout' => ['QA-456'],
            'password reset' => ['QA-457'],
        ];
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
            'linking' => ['enabled' => false],
        ]);

        $summary = $publisher->publish([
            [
                'status' => 'passed',
                'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'checkout'],
                'qality' => null,
            ],
            [
                'status' => 'passed',
                'test' => ['id' => 'PasswordTest::test_reset', 'name' => 'password reset'],
                'qality' => null,
            ],
        ]);

        self::assertSame(2, $summary->published);
        self::assertSame(1, $jira->bulkLookupCalls);
        self::assertSame([['checkout', 'password reset']], $jira->bulkLookupNames);
        self::assertSame(['10001', '10002'], $qality->addedCaseIds);
    }

    public function test_dry_run_does_not_perform_bulk_name_lookup(): void
    {
        $jira = new FakeJiraClient;
        $publisher = new QalityPublisher(new FakeQalityClient, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
        ]);

        $summary = $publisher->publish([[
            'status' => 'passed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'checkout'],
            'qality' => null,
        ]], dryRun: true);

        self::assertTrue($summary->dryRun);
        self::assertSame(0, $jira->bulkLookupCalls);
        self::assertSame(1, $summary->skipped);
    }

    public function test_it_resolves_legacy_method_name_cases_after_class_qualified_lookup_misses(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $jira->testCaseKeysByName['test_checkout'] = ['QA-456'];
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
            'linking' => ['enabled' => false],
        ]);

        $summary = $publisher->publish([[
            'status' => 'passed',
            'test' => [
                'id' => 'Tests\\Feature\\CheckoutTest::test_checkout',
                'name' => 'test_checkout',
                'class' => 'Tests\\Feature\\CheckoutTest',
                'method' => 'test_checkout',
            ],
            'qality' => null,
        ]]);

        self::assertSame(1, $summary->published);
        self::assertSame(['10001'], $qality->addedCaseIds);
    }

    public function test_it_uses_the_test_name_when_only_a_requirement_is_mapped(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $jira->testCaseKeysByName['test_checkout'] = ['QA-456'];
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
            'linking' => [
                'enabled' => true,
                'type' => 'Tests',
                'direction' => 'test_to_requirement',
            ],
        ]);

        $summary = $publisher->publish([[
            'status' => 'passed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => ['requirement_issue_key' => 'NDC-123'],
        ]]);

        self::assertSame(1, $summary->published);
        self::assertSame([['QA-456', 'NDC-123', 'Tests', 'test_to_requirement']], $jira->createdLinks);
    }

    public function test_it_rejects_ambiguous_name_matches(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $jira->testCaseKeysByName['test_checkout'] = ['QA-456', 'QA-789'];
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'test_case_resolver' => new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
        ]);

        $this->expectExceptionMessage('Multiple Jira test cases match [test_checkout]');

        $publisher->publish([[
            'status' => 'passed',
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ]]);
    }

    public function test_it_maps_all_supported_phpunit_outcomes_to_qality_statuses(): void
    {
        $qality = new FakeQalityClient;
        $publisher = new QalityPublisher($qality, new FakeJiraClient, [
            'project_id' => '20001',
            'linking' => ['enabled' => false],
        ]);
        $records = [];

        foreach (['passed', 'failed', 'error', 'risky', 'skipped', 'incomplete'] as $index => $status) {
            $records[] = [
                'status' => $status,
                'test' => ['id' => 'Example::test_'.$index, 'name' => 'test_'.$index],
                'qality' => ['issue_key' => 'QA-'.$index],
            ];
        }

        $summary = $publisher->publish($records);

        self::assertSame(6, $summary->published);
        self::assertSame(
            [1, 2, 2, 2, 3, 3],
            array_map(static fn (array $execution): int => $execution['fields']['statusId'], $qality->executions),
        );
    }

    public function test_it_creates_requirement_links_only_when_missing(): void
    {
        $qality = new FakeQalityClient;
        $jira = new FakeJiraClient;
        $publisher = new QalityPublisher($qality, $jira, [
            'project_id' => '20001',
            'linking' => [
                'enabled' => true,
                'type' => 'Tests',
                'direction' => 'test_to_requirement',
            ],
        ]);
        $record = [[
            'status' => 'passed',
            'test' => ['id' => 'Example::test_checkout', 'name' => 'test_checkout'],
            'qality' => [
                'issue_key' => 'QA-123',
                'requirement_issue_key' => 'REQ-42',
            ],
        ]];

        self::assertSame(1, $publisher->publish($record)->linked);
        self::assertSame([['QA-123', 'REQ-42', 'Tests', 'test_to_requirement']], $jira->createdLinks);

        $jira->linkExists = true;
        self::assertSame(0, $publisher->publish($record, cycleId: 'cycle-1')->linked);
        self::assertCount(1, $jira->createdLinks);
    }

    public function test_it_rejects_an_unknown_phpunit_status(): void
    {
        $publisher = new QalityPublisher(new FakeQalityClient, new FakeJiraClient, [
            'project_id' => '20001',
        ]);

        $this->expectException(PublisherException::class);
        $publisher->publish([[
            'status' => 'unknown',
            'test' => ['id' => 'Example::test'],
            'qality' => ['issue_key' => 'QA-123'],
        ]]);
    }
}

final class FakeQalityClient implements QalityClient
{
    public int $cycleCreates = 0;

    /** @var list<string> */
    public array $addedCaseIds = [];

    /** @var list<array<string, mixed>> */
    public array $executions = [];

    public function importTestCases(string $projectId, array $testCases): array
    {
        return ['success' => [], 'errors' => []];
    }

    public function createTestCycle(string $name, string $projectId, ?string $comment = null): array
    {
        $this->cycleCreates++;

        return ['id' => 'cycle-1'];
    }

    public function addTestCasesToCycle(string $cycleId, array $testCaseIds): array
    {
        $this->addedCaseIds = $testCaseIds;

        return array_map(
            static fn (string $testCaseId, int $index): array => [
                'id' => 'cycle-case-'.$index,
                'testCaseId' => (int) $testCaseId,
                'testExecution' => ['id' => 'execution-'.$index],
            ],
            $testCaseIds,
            array_keys($testCaseIds),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function listStatuses(): array
    {
        return [
            'statuses' => [
                ['id' => 1, 'name' => 'Passed', 'category' => 'PASSED'],
                ['id' => 2, 'name' => 'Failed', 'category' => 'FAILED'],
                ['id' => 3, 'name' => 'Unexecuted', 'category' => 'UNFINISHED'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateTestExecution(string $executionId, array $fields): array
    {
        $this->executions[] = [
            'executionId' => $executionId,
            'fields' => $fields,
        ];

        return ['id' => $executionId];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTestExecution(array $payload): array
    {
        return ['id' => 'execution-1'];
    }
}

final class FakeJiraClient implements JiraBulkTestCaseLookup, JiraClient, JiraTestCaseLookup
{
    public bool $linkExists = false;

    /** @var array<string, list<string>> */
    public array $testCaseKeysByName = [];

    public int $bulkLookupCalls = 0;

    /** @var list<list<string>> */
    public array $bulkLookupNames = [];

    /** @var array<string, string> */
    private array $issueIds = [];

    /** @var list<list<string>> */
    public array $createdLinks = [];

    public function issue(string $issueKey): array
    {
        $this->issueIds[$issueKey] ??= (string) (10001 + count($this->issueIds));

        return ['id' => $this->issueIds[$issueKey], 'key' => $issueKey];
    }

    public function findTestCaseKeysByName(string $name, string $projectKey, string $issueType): array
    {
        return $this->testCaseKeysByName[$name] ?? [];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public function findTestCaseKeysByNames(array $names, string $projectKey, string $issueType): array
    {
        $this->bulkLookupCalls++;
        $this->bulkLookupNames[] = $names;

        $matches = [];

        foreach ($names as $name) {
            if (isset($this->testCaseKeysByName[$name])) {
                $matches[$name] = $this->testCaseKeysByName[$name];
            }
        }

        return $matches;
    }

    public function issueLinkExists(string $testIssueKey, string $requirementIssueKey, string $linkType): bool
    {
        return $this->linkExists;
    }

    public function createIssueLink(string $testIssueKey, string $requirementIssueKey, string $linkType, string $direction = 'test_to_requirement'): void
    {
        $this->createdLinks[] = [$testIssueKey, $requirementIssueKey, $linkType, $direction];
    }
}
