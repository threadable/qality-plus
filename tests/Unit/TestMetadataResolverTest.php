<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use PHPUnit\Event\Code\TestMethodBuilder;
use Threadable\QalityPlus\PhpUnit\QalityTestCase;
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

        unlink($path);
    }

    #[QalityTestCase(name: 'Customer can complete checkout')]
    public function test_it_merges_a_name_attribute_with_the_persisted_mapping(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qality-map-');
        self::assertIsString($path);
        file_put_contents($path, json_encode([
            self::class.'::test_it_merges_a_name_attribute_with_the_persisted_mapping' => ['issue_key' => 'QA-789'],
        ], JSON_THROW_ON_ERROR));

        $test = TestMethodBuilder::fromTestCase($this);
        $metadata = (new TestMetadataResolver($path))->resolve($test);

        self::assertSame('QA-789', $metadata['issue_key'] ?? null);
        self::assertSame('Customer can complete checkout', $metadata['name'] ?? null);

        unlink($path);
    }

    #[QalityTestCase(requirementIssueKey: 'NDC-123')]
    public function test_it_allows_a_requirement_only_attribute(): void
    {
        $test = TestMethodBuilder::fromTestCase($this);
        $metadata = (new TestMetadataResolver)->resolve($test);

        self::assertSame('NDC-123', $metadata['requirement_issue_key'] ?? null);
        self::assertNull($metadata['name'] ?? null);
    }
}
