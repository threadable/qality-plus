<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use DateTimeImmutable;

final class QalityPublisher
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
    public function publish(array $records, ?string $cycleId = null, bool $dryRun = false): PublishSummary
    {
        $mapped = [];
        $skipped = 0;
        $testCaseResolver = $this->options['test_case_resolver'] ?? null;
        $resolvedIssueKeys = ! $dryRun && $testCaseResolver instanceof JiraTestCaseResolver
            ? $testCaseResolver->resolveMany($records)
            : [];

        foreach ($records as $recordIndex => $record) {
            $metadata = $record['qality'] ?? null;

            if (isset($resolvedIssueKeys[$recordIndex])) {
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['issue_key'] = $resolvedIssueKeys[$recordIndex];
                $record['qality'] = $metadata;
            }

            if (! is_array($metadata) || ! is_string($metadata['issue_key'] ?? null) || trim($metadata['issue_key']) === '') {
                $skipped++;

                continue;
            }

            $mapped[] = $record;
        }

        if ($dryRun || $mapped === []) {
            return new PublishSummary(
                total: count($records),
                published: $dryRun ? count($mapped) : 0,
                skipped: $skipped,
                linked: 0,
                cycleId: $cycleId,
                dryRun: $dryRun,
            );
        }

        $statusIds = $this->statusIds($this->qality->listStatuses());
        $cycleId = $cycleId ?: $this->configuredCycleId();

        if ($cycleId === null) {
            $projectId = $this->requiredString($this->options['project_id'] ?? null, 'QAlity project ID');
            $cycleName = $this->options['cycle_name'] ?? null;
            $cycleName = is_string($cycleName) && trim($cycleName) !== ''
                ? $cycleName
                : 'QAlity automated run '.(new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s\Z');
            $comment = $this->options['cycle_comment'] ?? null;
            $comment = is_string($comment) ? $comment : null;
            $cycle = $this->qality->createTestCycle($cycleName, $projectId, $comment);
            $cycleId = $this->scalarId($cycle, 'QAlity test cycle creation');
        }

        $issues = [];

        foreach ($mapped as $record) {
            $key = (string) $record['qality']['issue_key'];

            if (! isset($issues[$key])) {
                $issues[$key] = $this->jira->issue($key);
            }
        }

        $issueIds = [];

        foreach ($issues as $key => $issue) {
            $issueIds[$key] = $this->scalarId($issue, 'Jira issue lookup for '.$key);
        }

        $cycleCases = $this->qality->addTestCasesToCycle($cycleId, array_values($issueIds));
        $executionIds = $this->executionIds($cycleCases, $issueIds);
        $linked = 0;
        $published = 0;

        foreach ($mapped as $record) {
            $metadata = $record['qality'];
            $issueKey = (string) $metadata['issue_key'];
            $executionId = $executionIds[$issueKey] ?? null;

            if ($executionId === null) {
                throw new PublisherException(sprintf(
                    'QAlity did not return an execution for test case [%s] in cycle [%s].',
                    $issueKey,
                    $cycleId,
                ));
            }

            $fields = [
                'statusId' => $statusIds[$this->statusCategory((string) $record['status'])],
            ];

            if (isset($record['details']['message']) && is_string($record['details']['message'])) {
                $fields['comment'] = $record['details']['message'];
            } elseif (isset($record['details']['message']) && is_array($record['details']['message'])) {
                $fields['comment'] = (string) ($record['details']['message']['message'] ?? '');
            }

            $this->qality->updateTestExecution($executionId, $fields);
            $published++;

            if ($this->linkingEnabled() && is_string($metadata['requirement_issue_key'] ?? null)) {
                $linkType = $metadata['link_type'] ?? $this->options['linking']['type'] ?? null;
                $linkType = is_string($linkType) ? trim($linkType) : '';

                if ($linkType === '') {
                    throw new PublisherException('Jira linking is enabled but no issue-link type is configured.');
                }

                $requirementKey = $metadata['requirement_issue_key'];

                if (! $this->jira->issueLinkExists($issueKey, $requirementKey, $linkType)) {
                    $this->jira->createIssueLink(
                        $issueKey,
                        $requirementKey,
                        $linkType,
                        is_string($metadata['link_direction'] ?? null)
                            ? $metadata['link_direction']
                            : (string) ($this->options['linking']['direction'] ?? 'test_to_requirement'),
                    );
                    $linked++;
                }
            }
        }

        return new PublishSummary(
            total: count($records),
            published: $published,
            skipped: $skipped,
            linked: $linked,
            cycleId: $cycleId,
        );
    }

    private function configuredCycleId(): ?string
    {
        $cycleId = $this->options['cycle_id'] ?? null;

        return is_scalar($cycleId) && trim((string) $cycleId) !== '' ? (string) $cycleId : null;
    }

    private function linkingEnabled(): bool
    {
        return (bool) ($this->options['linking']['enabled'] ?? false);
    }

    private function statusCategory(string $status): string
    {
        return match ($status) {
            'passed' => 'PASSED',
            'failed', 'error', 'risky' => 'FAILED',
            'skipped', 'incomplete' => 'UNFINISHED',
            default => throw new PublisherException('Unsupported PHPUnit result status ['.$status.'].')
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function statusIds(array $payload): array
    {
        $statuses = $payload['statuses'] ?? $payload['data'] ?? $payload;

        if (! is_array($statuses)) {
            throw new PublisherException('QAlity status response was invalid.');
        }

        $ids = [];
        $priorities = [];

        foreach ($statuses as $status) {
            if (! is_array($status)) {
                continue;
            }

            $category = strtoupper((string) ($status['category'] ?? ''));
            $id = $status['id'] ?? null;

            if (! in_array($category, ['PASSED', 'FAILED', 'UNFINISHED'], true) || ! is_numeric($id)) {
                continue;
            }

            $name = strtolower(trim((string) ($status['name'] ?? '')));
            $priority = match ($name) {
                'passed', 'failed', 'unexecuted' => 0,
                default => 1,
            };

            if (! isset($priorities[$category]) || $priority < $priorities[$category]) {
                $ids[$category] = (int) $id;
                $priorities[$category] = $priority;
            }
        }

        foreach (['PASSED', 'FAILED', 'UNFINISHED'] as $category) {
            if (! isset($ids[$category])) {
                throw new PublisherException(sprintf('QAlity status category [%s] is not configured.', $category));
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scalarId(array $payload, string $operation): string
    {
        $id = $payload['id'] ?? ($payload['data']['id'] ?? null);

        if (! is_scalar($id) || trim((string) $id) === '') {
            throw new PublisherException('The '.$operation.' response did not contain an ID.');
        }

        return (string) $id;
    }

    /**
     * @param  list<array<string, mixed>>  $cycleCases
     * @return array<string, string>
     */
    private function executionIds(array $cycleCases, array $issueIds): array
    {
        $ids = [];

        foreach ($cycleCases as $case) {
            $testCaseId = $case['testCaseId'] ?? $case['test_case_id'] ?? null;
            $execution = $case['testExecution'] ?? $case['test_execution'] ?? null;
            $executionId = is_array($execution) ? ($execution['id'] ?? null) : null;

            if (is_scalar($testCaseId) && is_scalar($executionId)) {
                foreach ($issueIds as $issueKey => $issueId) {
                    if ((string) $issueId === (string) $testCaseId) {
                        $ids[$issueKey] = (string) $executionId;
                        break;
                    }
                }
            }
        }

        return $ids;
    }

    private function requiredString(mixed $value, string $name): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new PublisherException($name.' is not configured.');
        }

        return (string) $value;
    }
}
