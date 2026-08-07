<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use JsonException;

final class TestCaseMappingStore
{
    public function __construct(private readonly string $path) {}

    /**
     * @return array<string, array{issue_key: string}>
     */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        if (! is_readable($this->path)) {
            throw new PublisherException(sprintf('QAlity test-case mapping file [%s] is not readable.', $this->path));
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PublisherException(sprintf('QAlity test-case mapping file [%s] contains invalid JSON.', $this->path), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new PublisherException(sprintf('QAlity test-case mapping file [%s] must contain a JSON object.', $this->path));
        }

        $mappings = [];

        foreach ($decoded as $testId => $mapping) {
            if (! is_string($testId) || ! is_array($mapping) || ! is_string($mapping['issue_key'] ?? null) || trim($mapping['issue_key']) === '') {
                throw new PublisherException(sprintf('Each mapping in [%s] must contain a non-empty string issue_key.', $this->path));
            }

            $mappings[$testId] = ['issue_key' => $mapping['issue_key']];
        }

        return $mappings;
    }

    /**
     * @param  array<string, array{issue_key: string}>  $mappings
     */
    public function save(array $mappings): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new PublisherException(sprintf('Unable to create QAlity test-case mapping directory [%s].', $directory));
        }

        try {
            $contents = json_encode(
                $mappings,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            throw new PublisherException(sprintf('Unable to encode QAlity test-case mapping file [%s].', $this->path), previous: $exception);
        }

        $temporaryPath = $this->path.'.tmp.'.bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || ! rename($temporaryPath, $this->path)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw new PublisherException(sprintf('Unable to write QAlity test-case mapping file [%s].', $this->path));
        }
    }
}
