<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class CreateTestCasesService
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly QalityClient $qality,
        private readonly JiraClient $jira,
        private readonly array $options,
    ) {}

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

        foreach ($records as $record) {
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

            $name = is_string($test['name'] ?? null) && trim($test['name']) !== ''
                ? $test['name']
                : $testId;

            $eligible[] = [
                'id' => $testId,
                'name' => $name,
            ];
        }

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
        $imported = $this->qality->importTestCases($projectId, array_map(
            static fn (array $case): array => [
                'name' => $case['name'],
                'testSteps' => [],
            ],
            $eligible,
        ));

        $success = $imported['success'] ?? null;

        if (! is_array($success)) {
            throw new PublisherException('QAlity test-case import response did not contain a success list.');
        }

        $keysByName = [];

        foreach ($success as $case) {
            if (! is_array($case) || ! is_string($case['name'] ?? null) || ! is_string($case['testCaseKey'] ?? null) || trim($case['testCaseKey']) === '') {
                throw new PublisherException('QAlity test-case import response contained an invalid success entry.');
            }

            $keysByName[$case['name']][] = $case['testCaseKey'];
        }

        $createdCases = [];

        foreach ($eligible as $case) {
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

        if ($createdCases !== []) {
            $mappingStore->save($mappings);
        }

        $linked = $this->linkCases($createdCases, $workItemKey, $linkType, $direction);
        $errors = $imported['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            throw new PublisherException($this->importErrorMessage($errors));
        }

        if (count($createdCases) !== count($eligible)) {
            throw new PublisherException('QAlity test-case import did not return a case for every requested test.');
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
}
