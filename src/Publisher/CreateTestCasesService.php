<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Support\Facades\Log;

final class CreateTestCasesService
{
    private readonly TestCaseNameResolver $nameResolver;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly QalityClient $qality,
        private readonly JiraClient $jira,
        private readonly array $options,
        private readonly ?JiraTestCaseResolver $testCaseResolver = null,
    ) {
        $this->nameResolver = new TestCaseNameResolver;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function create(array $records, string $workItemKey, string $mappingPath, bool $dryRun = false): CreateTestCasesSummary
    {
        $mappingStore = new TestCaseMappingStore($mappingPath);
        $mappings = $mappingStore->load();
        $eligible = [];
        $seen = [];
        $skipped = 0;
        $resolvedMappings = false;
        $resolvedMappingCount = 0;
        $resolvedIssueKeys = ! $dryRun && $this->testCaseResolver !== null
            ? $this->testCaseResolver->resolveMany($records)
            : [];

        foreach ($records as $recordIndex => $record) {
            $test = $record['test'] ?? null;

            if (! is_array($test) || ! is_string($test['id'] ?? null) || trim($test['id']) === '') {
                throw new PublisherException('Each QAlity result must contain a non-empty test ID.');
            }

            $testId = $test['id'];

            if (isset($seen[$testId])) {
                continue;
            }

            $seen[$testId] = true;

            if ($this->hasIssueKey($record) || isset($mappings[$testId])) {
                $skipped++;

                continue;
            }

            if (isset($resolvedIssueKeys[$recordIndex])) {
                $issueKey = $resolvedIssueKeys[$recordIndex];

                $mappings[$testId] = ['issue_key' => $issueKey];
                $resolvedMappings = true;
                $resolvedMappingCount++;
                $skipped++;

                continue;
            }

            $name = $this->nameResolver->resolve($record);

            if ($name === null) {
                throw new PublisherException(sprintf('Unable to determine a QAlity test-case name for [%s].', $testId));
            }

            $eligible[] = [
                'id' => $testId,
                'name' => $name,
            ];
        }

        if ($resolvedMappings) {
            $mappingStore->save($mappings);
        }

        Log::info('qality-plus test case creation prepared', [
            'work_item' => $workItemKey,
            'record_count' => count($records),
            'unique_test_count' => count($seen),
            'eligible_count' => count($eligible),
            'skipped_count' => $skipped,
            'resolved_mapping_count' => $resolvedMappingCount,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun || $eligible === []) {
            return new CreateTestCasesSummary(
                total: count($records),
                eligible: count($eligible),
                created: 0,
                linked: 0,
                skipped: $skipped,
                workItemKey: $workItemKey,
                dryRun: $dryRun,
            );
        }

        $projectId = $this->requiredString($this->options['project_id'] ?? null, 'QAlity project ID');
        $linkType = $this->requiredString($this->options['link_type'] ?? null, 'Jira issue-link type');
        $direction = (string) ($this->options['link_direction'] ?? 'test_to_requirement');
        $createdCases = [];
        $linked = 0;
        $errors = [];
        $batches = array_chunk($eligible, $this->importBatchSize());
        $batchCount = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            Log::info('qality-plus QAlity test-case import started', [
                'work_item' => $workItemKey,
                'project_id' => $projectId,
                'batch' => $batchNumber,
                'batch_count' => $batchCount,
                'test_case_count' => count($batch),
                'total_test_case_count' => count($eligible),
                'request_method' => 'POST',
                'request_uri' => '/testCases/import',
            ]);
            $imported = $this->qality->importTestCases($projectId, array_map(
                static fn (array $case): array => [
                    'name' => $case['name'],
                    'testSteps' => [],
                ],
                $batch,
            ));

            $success = $imported['success'] ?? null;
            $batchErrors = $imported['errors'] ?? [];
            Log::info('qality-plus QAlity test-case import completed', [
                'work_item' => $workItemKey,
                'batch' => $batchNumber,
                'batch_count' => $batchCount,
                'success_count' => is_array($success) ? count($success) : null,
                'error_count' => is_array($batchErrors) ? count($batchErrors) : null,
            ]);

            if (! is_array($success)) {
                throw new PublisherException(sprintf(
                    'QAlity test-case import response did not contain a success list for batch %d of %d.',
                    $batchNumber,
                    $batchCount,
                ));
            }

            $batchCreatedCases = $this->mapImportedCases($batch, $success, $mappings);
            $createdCases = array_merge($createdCases, $batchCreatedCases);

            if ($batchCreatedCases !== []) {
                $mappingStore->save($mappings);
            }

            Log::info('qality-plus Jira test-case linking started', [
                'work_item' => $workItemKey,
                'batch' => $batchNumber,
                'batch_count' => $batchCount,
                'test_case_count' => count($batchCreatedCases),
                'link_type' => $linkType,
                'link_direction' => $direction,
            ]);
            $batchLinked = $this->linkCases($batchCreatedCases, $workItemKey, $linkType, $direction);
            $linked += $batchLinked;
            Log::info('qality-plus Jira test-case linking completed', [
                'work_item' => $workItemKey,
                'batch' => $batchNumber,
                'batch_count' => $batchCount,
                'test_case_count' => count($batchCreatedCases),
                'linked_count' => $batchLinked,
            ]);
            $this->labelCases($batchCreatedCases);

            if (is_array($batchErrors)) {
                $errors = array_merge($errors, $batchErrors);
            }
        }

        if ($errors !== []) {
            throw new PublisherException($this->importErrorMessage($errors));
        }

        if (count($createdCases) !== count($eligible)) {
            throw new PublisherException(sprintf(
                'QAlity test-case import did not return a case for every requested test (%d of %d).',
                count($createdCases),
                count($eligible),
            ));
        }

        return new CreateTestCasesSummary(
            total: count($records),
            eligible: count($eligible),
            created: count($createdCases),
            linked: $linked,
            skipped: $skipped,
            workItemKey: $workItemKey,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function hasIssueKey(array $record): bool
    {
        $metadata = $record['qality'] ?? null;

        return is_array($metadata)
            && is_string($metadata['issue_key'] ?? null)
            && trim($metadata['issue_key']) !== '';
    }

    /**
     * @param  list<string>  $caseKeys
     */
    private function linkCases(array $caseKeys, string $workItemKey, string $linkType, string $direction): int
    {
        $linked = 0;

        foreach ($caseKeys as $caseKey) {
            if ($this->jira->issueLinkExists($caseKey, $workItemKey, $linkType)) {
                continue;
            }

            $this->jira->createIssueLink($caseKey, $workItemKey, $linkType, $direction);
            $linked++;
        }

        return $linked;
    }

    /**
     * @param  list<string>  $caseKeys
     */
    private function labelCases(array $caseKeys): void
    {
        $label = $this->options['created_test_label'] ?? null;
        $label = is_string($label) ? trim($label) : '';

        if ($label === '' || $caseKeys === []) {
            return;
        }

        if (! $this->jira instanceof JiraIssueLabeler) {
            throw new PublisherException('A created-test label is configured but the Jira client does not support issue labels.');
        }

        Log::info('qality-plus Jira test-case labeling started', [
            'label' => $label,
            'test_case_count' => count($caseKeys),
        ]);

        foreach ($caseKeys as $caseKey) {
            $this->jira->addIssueLabel($caseKey, $label);
        }

        Log::info('qality-plus Jira test-case labeling completed', [
            'label' => $label,
            'test_case_count' => count($caseKeys),
        ]);
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function importErrorMessage(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $name = is_string($error['name'] ?? null) ? $error['name'] : 'unknown test case';
            $details = $error['details'] ?? null;
            $errorMessages = is_array($details) && is_array($details['errorMessages'] ?? null)
                ? array_values(array_filter($details['errorMessages'], 'is_string'))
                : [];
            $messages[] = $name.($errorMessages === [] ? '' : ': '.implode('; ', $errorMessages));
        }

        return 'QAlity test-case import failed for: '.implode(', ', $messages);
    }

    private function requiredString(mixed $value, string $name): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new PublisherException($name.' is not configured.');
        }

        return (string) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @param  list<mixed>  $success
     * @param  array<string, array{issue_key: string}>  $mappings
     * @return list<string>
     */
    private function mapImportedCases(array $batch, array $success, array &$mappings): array
    {
        $keysByName = [];

        foreach ($success as $case) {
            if (! is_array($case) || ! is_string($case['name'] ?? null) || ! is_string($case['testCaseKey'] ?? null) || trim($case['testCaseKey']) === '') {
                throw new PublisherException('QAlity test-case import response contained an invalid success entry.');
            }

            $keysByName[$case['name']][] = $case['testCaseKey'];
        }

        $createdCases = [];

        foreach ($batch as $case) {
            $key = null;

            if (isset($keysByName[$case['name']]) && $keysByName[$case['name']] !== []) {
                $key = array_shift($keysByName[$case['name']]);
            }

            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $mappings[$case['id']] = ['issue_key' => $key];
            $createdCases[] = $key;
        }

        return $createdCases;
    }

    private function importBatchSize(): int
    {
        $size = $this->options['import_batch_size'] ?? 50;

        return is_numeric($size) && (int) $size > 0 ? (int) $size : 50;
    }
}
