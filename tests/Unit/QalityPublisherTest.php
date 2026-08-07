<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\JiraClient;
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
        self::assertSame('failed', $qality->executions[0]['status']);
        self::assertSame(10001, $qality->executions[0]['testCaseId']);
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

        return [['id' => 'cycle-case-1', 'testCaseId' => 10001]];
    }

    public function createTestExecution(array $payload): array
    {
        $this->executions[] = $payload;

        return ['id' => 'execution-1'];
    }
}

final class FakeJiraClient implements JiraClient
{
    public function issue(string $issueKey): array
    {
        return ['id' => '10001', 'key' => $issueKey];
    }

    public function issueLinkExists(string $testIssueKey, string $requirementIssueKey, string $linkType): bool
    {
        return false;
    }

    public function createIssueLink(string $testIssueKey, string $requirementIssueKey, string $linkType, string $direction = 'test_to_requirement'): void {}
}
