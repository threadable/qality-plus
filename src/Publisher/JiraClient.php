<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraClient
{
    /**
     * @return array<string, mixed>
     */
    public function issue(string $issueKey): array;

    public function issueLinkExists(string $testIssueKey, string $requirementIssueKey, string $linkType): bool;

    public function createIssueLink(
        string $testIssueKey,
        string $requirementIssueKey,
        string $linkType,
        string $direction = 'test_to_requirement',
    ): void;
}
