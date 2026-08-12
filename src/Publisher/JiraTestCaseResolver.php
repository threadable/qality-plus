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
        if ($this->projectKey === null || trim($this->projectKey) === '' || ! $this->jira instanceof JiraTestCaseLookup) {
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
        if ($this->projectKey === null || trim($this->projectKey) === '' || ! $this->jira instanceof JiraTestCaseLookup) {
            return [];
        }

        $namesByRecord = [];
        $names = [];

        foreach ($records as $index => $record) {
            $metadata = $record['qality'] ?? null;

            if (is_array($metadata) && is_string($metadata['issue_key'] ?? null) && trim($metadata['issue_key']) !== '') {
                continue;
            }

            $candidates = $this->nameResolver->candidates($record);

            if ($candidates === []) {
                continue;
            }

            $namesByRecord[$index] = $candidates;
            $names = array_merge($names, $candidates);
        }

        if ($namesByRecord === []) {
            return [];
        }

        $matches = $this->lookup(array_values(array_unique($names)));
        $resolved = [];

        foreach ($namesByRecord as $index => $candidates) {
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
