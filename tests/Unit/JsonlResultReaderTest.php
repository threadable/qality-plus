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
}
