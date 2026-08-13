<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\CreateTestCasesService;
use Threadable\QalityPlus\Publisher\JiraAutomationTestCaseLookup;
use Threadable\QalityPlus\Publisher\JiraBulkTestCaseLookup;
use Threadable\QalityPlus\Publisher\JiraClient;
use Threadable\QalityPlus\Publisher\JiraIssueLabeler;
use Threadable\QalityPlus\Publisher\JiraTestCaseLookup;
use Threadable\QalityPlus\Publisher\JiraTestCaseResolver;
use Threadable\QalityPlus\Publisher\QalityClient;
use Threadable\QalityPlus\Publisher\TestCaseAutomationLabel;
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
                'qality' => [
                    'issue_key' => 'QA-999',
                    'requirement_issue_key' => 'PROJ-456',
                ],
            ],
        ], 'PROJ-123', $path);

        self::assertSame(1, $summary->eligible);
        self::assertSame(1, $summary->created);
        self::assertSame(2, $summary->linked);
        self::assertSame([
            'projectId' => '20001',
            'testCases' => [['name' => 'test_checkout', 'testSteps' => []]],
        ], $qality->importPayload);
        self::assertSame([
            ['QA-999', 'PROJ-456', 'Tests', 'test_to_requirement'],
            ['QA-123', 'PROJ-123', 'Tests', 'test_to_requirement'],
        ], $jira->createdLinks);
        self::assertSame(['issue_key' => 'QA-123'], json_decode((string) file_get_contents($path), true)['CheckoutTest::test_checkout']);
    }

    public function test_it_relinks_cases_already_persisted_in_the_mapping_file(): void
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
        self::assertSame([['QA-123', 'PROJ-123', 'Tests', 'test_to_requirement']], $jira->createdLinks);
    }

    public function test_it_uses_an_attribute_name_when_creating_a_missing_case(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
        ]);

        $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => ['name' => 'Customer can complete checkout'],
        ]], 'PROJ-123', $path);

        self::assertSame([
            'projectId' => '20001',
            'testCases' => [['name' => 'Customer can complete checkout', 'testSteps' => []]],
        ], $qality->importPayload);
    }

    public function test_an_annotated_requirement_overrides_the_branch_work_item(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
        ]);

        $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => ['requirement_issue_key' => 'NDCPR-33'],
        ]], 'NDCPR-562', $path);

        self::assertSame([['QA-123', 'NDCPR-33', 'Tests', 'test_to_requirement']], $jira->createdLinks);
    }

    public function test_it_uses_a_class_qualified_name_when_creating_a_missing_phpunit_case(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
        ]);

        $service->create([[
            'test' => [
                'id' => 'Tests\\Feature\\CheckoutTest::test_checkout',
                'name' => 'test_checkout',
                'class' => 'Tests\\Feature\\CheckoutTest',
                'method' => 'test_checkout',
            ],
            'qality' => null,
        ]], 'PROJ-123', $path);

        self::assertSame([
            'projectId' => '20001',
            'testCases' => [[
                'name' => 'Tests\\Feature\\CheckoutTest::test_checkout',
                'testSteps' => [],
            ]],
        ], $qality->importPayload);
    }

    public function test_it_labels_created_cases_when_a_label_is_configured(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
            'created_test_label' => 'threadable-qality-plus',
        ]);

        $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ]], 'PROJ-123', $path);

        self::assertSame([
            ['QA-123', TestCaseAutomationLabel::forTestId('CheckoutTest::test_checkout')],
            ['QA-123', 'threadable-qality-plus'],
        ], $jira->addedLabels);
    }

    public function test_it_does_not_label_created_cases_when_the_label_is_empty(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $service = new CreateTestCasesService($qality, $jira, [
            'project_id' => '20001',
            'link_type' => 'Tests',
            'created_test_label' => '',
        ]);

        $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => null,
        ]], 'PROJ-123', $path);

        self::assertSame([
            ['QA-123', TestCaseAutomationLabel::forTestId('CheckoutTest::test_checkout')],
        ], $jira->addedLabels);
    }

    public function test_it_resolves_an_existing_case_by_automation_label_before_name(): void
    {
        $path = $this->temporaryMappingPath();
        $testId = 'P\\Tests\\Unit\\Support\\Faker\\ValidPhoneNumberProviderTest::__pest_evaluable_facility_factory_can_generate_a_valid_phone_number';
        $label = TestCaseAutomationLabel::forTestId($testId);
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $jira->testCaseKeysByAutomationLabel[$label] = ['NDCPR-4677'];
        $service = new CreateTestCasesService(
            $qality,
            $jira,
            [
                'project_id' => '20001',
                'link_type' => 'Tests',
            ],
            new JiraTestCaseResolver($jira, 'NDCPR', 'QAlity Test'),
        );

        $summary = $service->create([[
            'test' => ['id' => $testId, 'name' => '__pest_evaluable_facility_factory_can_generate_a_valid_phone_number'],
            'qality' => null,
        ]], 'NDCPR-562', $path);

        self::assertSame(0, $summary->eligible);
        self::assertSame(1, $summary->skipped);
        self::assertSame(0, $qality->importCalls);
        self::assertSame([
            ['NDCPR-4677', $label],
        ], $jira->addedLabels);
    }

    public function test_it_resolves_an_existing_case_by_name_and_persists_the_mapping(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $jira->testCaseKeysByName['Customer can complete checkout'] = ['QA-456'];
        $service = new CreateTestCasesService(
            $qality,
            $jira,
            [
                'project_id' => '20001',
                'link_type' => 'Tests',
            ],
            new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
        );

        $summary = $service->create([[
            'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'test_checkout'],
            'qality' => ['name' => 'Customer can complete checkout'],
        ]], 'PROJ-123', $path);

        self::assertSame(0, $summary->eligible);
        self::assertSame(1, $summary->skipped);
        self::assertSame(1, $summary->linked);
        self::assertSame([], $qality->importPayload);
        self::assertSame('QA-456', json_decode((string) file_get_contents($path), true)['CheckoutTest::test_checkout']['issue_key']);
        self::assertSame([['QA-456', 'PROJ-123', 'Tests', 'test_to_requirement']], $jira->createdLinks);
    }

    public function test_it_resolves_multiple_existing_cases_with_one_bulk_lookup(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $jira = new CreateFakeJiraClient;
        $jira->testCaseKeysByName = [
            'checkout' => ['QA-456'],
            'password reset' => ['QA-457'],
        ];
        $service = new CreateTestCasesService(
            $qality,
            $jira,
            [
                'project_id' => '20001',
                'link_type' => 'Tests',
            ],
            new JiraTestCaseResolver($jira, 'QA', 'QAlity Test'),
        );

        $summary = $service->create([
            [
                'test' => ['id' => 'CheckoutTest::test_checkout', 'name' => 'checkout'],
                'qality' => null,
            ],
            [
                'test' => ['id' => 'PasswordTest::test_reset', 'name' => 'password reset'],
                'qality' => null,
            ],
        ], 'PROJ-123', $path);

        self::assertSame(0, $summary->eligible);
        self::assertSame(2, $summary->skipped);
        self::assertSame(2, $summary->linked);
        self::assertSame(1, $jira->bulkLookupCalls);
        self::assertSame([['checkout', 'password reset']], $jira->bulkLookupNames);
        self::assertSame([
            ['QA-456', 'PROJ-123', 'Tests', 'test_to_requirement'],
            ['QA-457', 'PROJ-123', 'Tests', 'test_to_requirement'],
        ], $jira->createdLinks);
        self::assertSame([
            'CheckoutTest::test_checkout' => ['issue_key' => 'QA-456'],
            'PasswordTest::test_reset' => ['issue_key' => 'QA-457'],
        ], json_decode((string) file_get_contents($path), true));
    }

    public function test_it_imports_missing_cases_in_configured_batches(): void
    {
        $path = $this->temporaryMappingPath();
        $qality = new CreateFakeQalityClient;
        $service = new CreateTestCasesService($qality, new CreateFakeJiraClient, [
            'project_id' => '20001',
            'link_type' => 'Tests',
            'import_batch_size' => 2,
            'created_test_label' => '',
        ]);
        $records = [];

        foreach (range(1, 5) as $index) {
            $records[] = [
                'test' => ['id' => 'CheckoutTest::test_'.$index, 'name' => 'checkout '.$index],
                'qality' => null,
            ];
        }

        $summary = $service->create($records, 'PROJ-123', $path);

        self::assertSame(5, $summary->created);
        self::assertSame(3, $qality->importCalls);
        self::assertSame([2, 2, 1], array_map(
            static fn (array $payload): int => count($payload['testCases']),
            $qality->importPayloads,
        ));
        self::assertCount(5, json_decode((string) file_get_contents($path), true));
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

    public int $importCalls = 0;

    /** @var list<array<string, mixed>> */
    public array $importPayloads = [];

    public function importTestCases(string $projectId, array $testCases): array
    {
        $this->importCalls++;
        $this->importPayload = [
            'projectId' => $projectId,
            'testCases' => $testCases,
        ];
        $this->importPayloads[] = $this->importPayload;

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

final class CreateFakeJiraClient implements JiraAutomationTestCaseLookup, JiraBulkTestCaseLookup, JiraClient, JiraIssueLabeler, JiraTestCaseLookup
{
    /** @var list<list<string>> */
    public array $createdLinks = [];

    /** @var array<string, list<string>> */
    public array $testCaseKeysByName = [];

    /** @var array<string, list<string>> */
    public array $testCaseKeysByAutomationLabel = [];

    public int $bulkLookupCalls = 0;

    /** @var list<list<string>> */
    public array $bulkLookupNames = [];

    /** @var list<list<string>> */
    public array $addedLabels = [];

    public function issue(string $issueKey): array
    {
        return ['id' => '10001', 'key' => $issueKey];
    }

    public function findTestCaseKeysByName(string $name, string $projectKey, string $issueType): array
    {
        return $this->testCaseKeysByName[$name] ?? [];
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, list<string>>
     */
    public function findTestCaseKeysByAutomationLabels(array $labels, string $projectKey, string $issueType): array
    {
        $matches = [];

        foreach ($labels as $label) {
            if (isset($this->testCaseKeysByAutomationLabel[$label])) {
                $matches[$label] = $this->testCaseKeysByAutomationLabel[$label];
            }
        }

        return $matches;
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

    public function addIssueLabel(string $issueKey, string $label): void
    {
        $this->addedLabels[] = [$issueKey, $label];
    }
}
