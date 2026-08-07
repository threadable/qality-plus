<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\PhpUnit\ResultWriter;
use Threadable\QalityPlus\Tests\TestCase;

final class ResultWriterTest extends TestCase
{
    public function test_it_writes_versioned_jsonl_records_with_stable_run_id(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qality-plus-'.bin2hex(random_bytes(4));
        $writer = new ResultWriter($directory, 1, 'run-123');

        $writer->write(['status' => 'passed', 'test' => ['id' => 'Example::test']]);
        $writer->write(['status' => 'failed', 'test' => ['id' => 'Example::other']]);
        $writer->close();

        self::assertSame($directory.'/qality-results.v1.run-123.jsonl', $writer->path());
        self::assertFileExists($writer->path());
        self::assertCount(2, file($writer->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($writer->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        self::assertSame(1, $records[0]['schema_version']);
        self::assertSame('run-123', $records[1]['run_id']);
        self::assertSame(2, $records[1]['sequence']);
    }
}
