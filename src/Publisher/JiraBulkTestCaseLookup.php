<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraBulkTestCaseLookup
{
    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public function findTestCaseKeysByNames(array $names, string $projectKey, string $issueType): array;
}
