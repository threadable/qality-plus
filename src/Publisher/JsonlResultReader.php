<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use JsonException;
use SplFileObject;

final class JsonlResultReader
{
    public function __construct(private readonly int $schemaVersion = 1) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function readPath(string $path): array
    {
        $files = is_dir($path) ? $this->filesInDirectory($path) : [$path];
        $records = [];

        foreach ($files as $file) {
            $records = [...$records, ...$this->readFile($file)];
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new PublisherException(sprintf('QAlity result file [%s] does not exist or is not readable.', $path));
        }

        $file = new SplFileObject($path, 'rb');
        $records = [];
        $lineNumber = 0;

        while (! $file->eof()) {
            $lineNumber++;
            $line = trim((string) $file->fgets());

            if ($line === '') {
                continue;
            }

            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new PublisherException(sprintf('Invalid JSON in [%s] on line %d: %s', $path, $lineNumber, $exception->getMessage()), previous: $exception);
            }

            if (! is_array($record)) {
                throw new PublisherException(sprintf('QAlity result [%s] line %d must contain a JSON object.', $path, $lineNumber));
            }

            $this->validate($record, $path, $lineNumber);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function validate(array $record, string $path, int $lineNumber): void
    {
        if (($record['schema_version'] ?? null) !== $this->schemaVersion) {
            throw new PublisherException(sprintf('Unsupported QAlity result schema in [%s] line %d.', $path, $lineNumber));
        }

        foreach (['run_id', 'sequence', 'recorded_at', 'status', 'test'] as $field) {
            if (! array_key_exists($field, $record)) {
                throw new PublisherException(sprintf('Missing QAlity result field [%s] in [%s] line %d.', $field, $path, $lineNumber));
            }
        }

        if (! is_array($record['test']) || ! is_string($record['test']['id'] ?? null)) {
            throw new PublisherException(sprintf('Invalid QAlity test identity in [%s] line %d.', $path, $lineNumber));
        }

        if (! is_string($record['status'])) {
            throw new PublisherException(sprintf('Invalid QAlity test status in [%s] line %d.', $path, $lineNumber));
        }
    }

    /**
     * @return list<string>
     */
    private function filesInDirectory(string $directory): array
    {
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'qality-results.v'.$this->schemaVersion.'.*.jsonl') ?: [];
        sort($files);

        if ($files === []) {
            throw new PublisherException(sprintf('No version %d QAlity JSONL result files were found in [%s].', $this->schemaVersion, $directory));
        }

        return $files;
    }
}
