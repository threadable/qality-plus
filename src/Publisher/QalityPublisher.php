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

        foreach ($records as $record) {
            $metadata = $record['qality'] ?? null;

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
        $cycleCaseIds = $this->cycleCaseIds($cycleCases, $issueIds);
        $linked = 0;
        $published = 0;

        foreach ($mapped as $record) {
            $metadata = $record['qality'];
            $issueKey = (string) $metadata['issue_key'];
            $payload = [
                'name' => (string) ($record['test']['name'] ?? $record['test']['id']),
                'status' => $this->status((string) $record['status']),
                'testCaseId' => (int) $issueIds[$issueKey],
                'testSteps' => [],
            ];

            if (isset($cycleCaseIds[$issueKey])) {
                $payload['testCaseInCycleId'] = $cycleCaseIds[$issueKey];
            }

            if (isset($record['details']['message']) && is_string($record['details']['message'])) {
                $payload['comment'] = $record['details']['message'];
            } elseif (isset($record['details']['message']) && is_array($record['details']['message'])) {
                $payload['comment'] = (string) ($record['details']['message']['message'] ?? '');
            }

            $this->qality->createTestExecution($payload);
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

    private function status(string $status): string
    {
        return match ($status) {
            'passed' => 'passed',
            'failed', 'error', 'risky' => 'failed',
            'skipped', 'incomplete' => 'unexecuted',
            default => throw new PublisherException('Unsupported PHPUnit result status ['.$status.'].')
        };
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
    private function cycleCaseIds(array $cycleCases, array $issueIds): array
    {
        $ids = [];

        foreach ($cycleCases as $case) {
            $testCaseId = $case['testCaseId'] ?? $case['test_case_id'] ?? null;
            $cycleCaseId = $case['id'] ?? $case['testCaseInCycleId'] ?? $case['test_case_in_cycle_id'] ?? null;

            if (is_scalar($testCaseId) && is_scalar($cycleCaseId)) {
                foreach ($issueIds as $issueKey => $issueId) {
                    if ((string) $issueId === (string) $testCaseId) {
                        $ids[$issueKey] = (string) $cycleCaseId;
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
