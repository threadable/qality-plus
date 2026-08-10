<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use JsonException;
use PHPUnit\Event\Code\Test;
use ReflectionMethod;
use RuntimeException;

final class TestMetadataResolver
{
    /**
     * @var array<string, array<string, string|null>>
     */
    private readonly array $mappings;

    public function __construct(?string $mappingFile = null)
    {
        $this->mappings = $this->loadMappings($mappingFile ?: (getenv('QALITY_TEST_MAPPING_FILE') ?: null));
    }

    /**
     * @return array<string, string|null>|null
     */
    public function resolve(Test $test): ?array
    {
        if (! $test->isTestMethod()) {
            return null;
        }

        /** @var object{className: callable, methodName: callable} $test */
        $className = $test->className();
        $methodName = $test->methodName();
        $method = new ReflectionMethod($className, $methodName);
        $attribute = $method->getAttributes(QalityTestCase::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->toArray();
        }

        $id = $test->id();

        return $this->mappings[$id] ?? $this->mappings[explode('#', $id, 2)[0]] ?? null;
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function loadMappings(?string $mappingFile): array
    {
        if ($mappingFile === null || trim($mappingFile) === '') {
            return [];
        }

        if (! is_file($mappingFile) || ! is_readable($mappingFile)) {
            throw new RuntimeException(sprintf('QAlity test mapping file [%s] does not exist or is not readable.', $mappingFile));
        }

        try {
            $decoded = json_decode((string) file_get_contents($mappingFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('QAlity test mapping file contains invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('QAlity test mapping file must contain a JSON object.');
        }

        $mappings = [];

        foreach ($decoded as $testId => $mapping) {
            if (! is_string($testId) || ! is_array($mapping) || ! is_string($mapping['issue_key'] ?? null)) {
                throw new RuntimeException('Each QAlity test mapping must contain a string issue_key.');
            }

            $mappings[$testId] = [
                'issue_key' => $mapping['issue_key'],
                'requirement_issue_key' => is_string($mapping['requirement_issue_key'] ?? null) ? $mapping['requirement_issue_key'] : null,
                'link_type' => is_string($mapping['link_type'] ?? null) ? $mapping['link_type'] : null,
                'link_direction' => is_string($mapping['link_direction'] ?? null) ? $mapping['link_direction'] : null,
            ];
        }

        return $mappings;
    }
}
