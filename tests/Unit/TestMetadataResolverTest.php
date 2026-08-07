<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use PHPUnit\Event\Code\TestMethodBuilder;
use Threadable\QalityPlus\PhpUnit\TestMetadataResolver;
use Threadable\QalityPlus\Tests\TestCase;

final class TestMetadataResolverTest extends TestCase
{
    public function test_it_resolves_an_explicit_mapping_file_for_a_test_id(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-map-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            self::class.'::test_it_resolves_an_explicit_mapping_file_for_a_test_id' => ['issue_key' => 'QA-456'],
        ], JSON_THROW_ON_ERROR));

        $test = TestMethodBuilder::fromTestCase($this);
        $metadata = (new TestMetadataResolver($path))->resolve($test);

        self::assertSame('QA-456', $metadata['issue_key'] ?? null);
    }
}
