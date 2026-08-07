<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\JsonlResultReader;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Tests\TestCase;

final class JsonlResultReaderTest extends TestCase
{
    public function test_it_reads_and_validates_versioned_result_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'run_id' => 'run-1',
            'sequence' => 1,
            'recorded_at' => '2026-08-07T00:00:00Z',
            'status' => 'passed',
            'test' => ['id' => 'Example::test'],
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        $records = (new JsonlResultReader)->readPath($path);

        self::assertCount(1, $records);
        self::assertSame('Example::test', $records[0]['test']['id']);
    }

    public function test_it_rejects_unsupported_schema_versions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-');
        self::assertIsString($path);
        file_put_contents($path, '{"schema_version":2}'.PHP_EOL);

        $this->expectException(PublisherException::class);
        (new JsonlResultReader)->readPath($path);
    }

    public function test_it_rejects_invalid_json_with_the_line_number(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-');
        self::assertIsString($path);
        file_put_contents($path, "{invalid}\n");

        try {
            (new JsonlResultReader)->readPath($path);
            self::fail('Expected a PublisherException.');
        } catch (PublisherException $exception) {
            self::assertStringContainsString('line 1', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_result_objects_with_missing_required_fields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'run_id' => 'run-1',
            'sequence' => 1,
            'test' => ['id' => 'Example::test'],
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        try {
            (new JsonlResultReader)->readPath($path);
            self::fail('Expected a PublisherException.');
        } catch (PublisherException $exception) {
            self::assertStringContainsString('recorded_at', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_it_reads_versioned_files_in_sorted_order(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-reader-'.bin2hex(random_bytes(4));
        mkdir($directory);

        foreach (['b' => 'second', 'a' => 'first'] as $file => $testId) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'qality-results.v1.'.$file.'.jsonl', json_encode([
                'schema_version' => 1,
                'run_id' => 'run-1',
                'sequence' => 1,
                'recorded_at' => '2026-08-07T00:00:00Z',
                'status' => 'passed',
                'test' => ['id' => $testId],
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }

        try {
            $records = (new JsonlResultReader)->readPath($directory);

            self::assertSame(['first', 'second'], array_column(array_column($records, 'test'), 'id'));
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_it_rejects_an_empty_results_directory(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-reader-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $this->expectException(PublisherException::class);
            (new JsonlResultReader)->readPath($directory);
        } finally {
            rmdir($directory);
        }
    }
}
