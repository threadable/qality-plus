<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class JiraTestCaseResolver
{
    private readonly TestCaseNameResolver $nameResolver;

    public function __construct(
        private readonly JiraClient $jira,
        private readonly ?string $projectKey,
        private readonly string $issueType = 'QAlity Test',
    ) {
        $this->nameResolver = new TestCaseNameResolver;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function resolve(array $record): ?string
    {
        if (! $this->canLookup()) {
            return null;
        }

        $label = $this->automationLabel($record);

        if ($label !== null && $this->jira instanceof JiraAutomationTestCaseLookup) {
            $issueKey = $this->resolveMatches(
                'automation label '.$label,
                $this->lookupLabels([$label]),
                $label,
            );

            if ($issueKey !== null) {
                return $issueKey;
            }
        }

        if (! $this->jira instanceof JiraTestCaseLookup) {
            return null;
        }

        $names = $this->nameResolver->candidates($record);

        if ($names === []) {
            return null;
        }

        return $this->resolveNames($names, $this->lookup($names));
    }

    /**
     * Resolve all records with one or more batched Jira searches when supported
     * by the client. The returned keys use the record's array index.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array<int, string>
     */
    public function resolveMany(array $records): array
    {
        if (! $this->canLookup()) {
            return [];
        }

        $labelsByRecord = [];
        $labels = [];
        $namesByRecord = [];
        $names = [];

        foreach ($records as $index => $record) {
            $metadata = $record['qality'] ?? null;

            if (is_array($metadata) && is_string($metadata['issue_key'] ?? null) && trim($metadata['issue_key']) !== '') {
                continue;
            }

            $label = $this->automationLabel($record);

            if ($label !== null && $this->jira instanceof JiraAutomationTestCaseLookup) {
                $labelsByRecord[$index] = $label;
                $labels[] = $label;
            }

            $candidates = $this->jira instanceof JiraTestCaseLookup
                ? $this->nameResolver->candidates($record)
                : [];

            if ($candidates === []) {
                continue;
            }

            $namesByRecord[$index] = $candidates;
            $names = array_merge($names, $candidates);
        }

        if ($labelsByRecord === [] && $namesByRecord === []) {
            return [];
        }

        $resolved = [];

        if ($labelsByRecord !== []) {
            $labelMatches = $this->lookupLabels(array_values(array_unique($labels)));

            foreach ($labelsByRecord as $index => $label) {
                $issueKey = $this->resolveMatches('automation label '.$label, $labelMatches, $label);

                if ($issueKey !== null) {
                    $resolved[$index] = $issueKey;
                }
            }
        }

        $unresolvedNames = array_diff_key($namesByRecord, $resolved);

        if ($unresolvedNames === []) {
            return $resolved;
        }

        $unresolvedNamesList = [];

        foreach ($unresolvedNames as $candidates) {
            $unresolvedNamesList = [...$unresolvedNamesList, ...$candidates];
        }

        $matches = $this->lookup(array_values(array_unique($unresolvedNamesList)));

        foreach ($unresolvedNames as $index => $candidates) {
            $issueKey = $this->resolveNames($candidates, $matches);

            if ($issueKey !== null) {
                $resolved[$index] = $issueKey;
            }
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, list<string>>  $matches
     */
    private function resolveNames(array $names, array $matches): ?string
    {
        foreach ($names as $name) {
            $keys = $matches[$name] ?? [];

            if (count($keys) > 1) {
                throw new PublisherException(sprintf(
                    'Multiple Jira test cases match [%s]: %s. Provide an issue key or make the test-case name unique.',
                    $name,
                    implode(', ', $keys),
                ));
            }

            if ($keys !== []) {
                return $keys[0];
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $matches
     */
    private function resolveMatches(string $description, array $matches, string $key): ?string
    {
        $keys = $matches[$key] ?? [];

        if (count($keys) > 1) {
            throw new PublisherException(sprintf(
                'Multiple Jira test cases match %s: %s.',
                $description,
                implode(', ', $keys),
            ));
        }

        return $keys[0] ?? null;
    }

    private function canLookup(): bool
    {
        return $this->projectKey !== null
            && trim($this->projectKey) !== ''
            && ($this->jira instanceof JiraAutomationTestCaseLookup || $this->jira instanceof JiraTestCaseLookup);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function automationLabel(array $record): ?string
    {
        $test = $record['test'] ?? null;

        return is_array($test)
            && is_string($test['id'] ?? null)
            && trim($test['id']) !== ''
            ? TestCaseAutomationLabel::forTestId($test['id'])
            : null;
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, list<string>>
     */
    private function lookupLabels(array $labels): array
    {
        return $this->jira instanceof JiraAutomationTestCaseLookup
            ? $this->jira->findTestCaseKeysByAutomationLabels($labels, (string) $this->projectKey, $this->issueType)
            : [];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    private function lookup(array $names): array
    {
        if ($this->jira instanceof JiraBulkTestCaseLookup) {
            return $this->jira->findTestCaseKeysByNames($names, (string) $this->projectKey, $this->issueType);
        }

        $matches = [];

        foreach ($names as $name) {
            $keys = $this->jira->findTestCaseKeysByName($name, (string) $this->projectKey, $this->issueType);

            if ($keys !== []) {
                $matches[$name] = $keys;
            }
        }

        return $matches;
    }
}
