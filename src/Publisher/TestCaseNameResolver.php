<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class TestCaseNameResolver
{
    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    public function candidates(array $record): array
    {
        $name = $this->resolve($record);

        if ($name === null) {
            return [];
        }

        $metadata = $record['qality'] ?? null;

        if (is_array($metadata) && is_string($metadata['name'] ?? null) && trim($metadata['name']) !== '') {
            return [$name];
        }

        $test = $record['test'] ?? null;

        if (! is_array($test)
            || ! is_string($test['class'] ?? null)
            || trim($test['class']) === ''
            || ! is_string($test['method'] ?? null)
            || trim($test['method']) === ''
            || ! is_string($test['name'] ?? null)
            || trim($test['name']) === ''
            || $test['name'] === $name) {
            return [$name];
        }

        return [$name, $test['name']];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function resolve(array $record): ?string
    {
        $metadata = $record['qality'] ?? null;

        if (is_array($metadata) && is_string($metadata['name'] ?? null) && trim($metadata['name']) !== '') {
            return $metadata['name'];
        }

        $test = $record['test'] ?? null;

        if (! is_array($test)) {
            return null;
        }

        if (is_string($test['class'] ?? null)
            && trim($test['class']) !== ''
            && is_string($test['method'] ?? null)
            && trim($test['method']) !== '') {
            return $test['class'].'::'.$test['method'];
        }

        if (is_string($test['name'] ?? null) && trim($test['name']) !== '') {
            return $test['name'];
        }

        return is_string($test['id'] ?? null) && trim($test['id']) !== '' ? $test['id'] : null;
    }
}
