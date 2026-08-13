<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraAutomationTestCaseLookup
{
    /**
     * @param  list<string>  $labels
     * @return array<string, list<string>>
     */
    public function findTestCaseKeysByAutomationLabels(array $labels, string $projectKey, string $issueType): array;
}
