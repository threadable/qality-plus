<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraTestCaseLookup
{
    /**
     * @return list<string>
     */
    public function findTestCaseKeysByName(string $name, string $projectKey, string $issueType): array;
}
