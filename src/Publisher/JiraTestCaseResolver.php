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

        $name = $this->nameResolver->resolve($record);

        if ($name === null) {
            return null;
        }

        $keys = $this->jira->findTestCaseKeysByName($name, $this->projectKey, $this->issueType);

        if (count($keys) > 1) {
            throw new PublisherException(sprintf(
                'Multiple Jira test cases match [%s]: %s. Provide an issue key or make the test-case name unique.',
                $name,
                implode(', ', $keys),
            ));
        }

        return $keys[0] ?? null;
    }
}
