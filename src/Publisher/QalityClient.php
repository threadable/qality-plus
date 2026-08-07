<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface QalityClient
{
    /**
     * @param  list<array<string, mixed>>  $testCases
     * @return array<string, mixed>
     */
    public function importTestCases(string $projectId, array $testCases): array;

    /**
     * @return array<string, mixed>
     */
    public function createTestCycle(string $name, string $projectId, ?string $comment = null): array;

    /**
     * @param  list<string>  $testCaseIds
     * @return list<array<string, mixed>>
     */
    public function addTestCasesToCycle(string $cycleId, array $testCaseIds): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTestExecution(array $payload): array;
}
