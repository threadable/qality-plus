<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Threadable\QalityPlus\PhpUnit\QalityTestCase;

final class ExtensionFixtureTest extends TestCase
{
    #[QalityTestCase('QA-EXT-123', requirementIssueKey: 'PROJ-456')]
    public function test_annotated_passes(): void
    {
        self::assertTrue(true);
    }

    public function test_fails(): void
    {
        self::fail('expected fixture failure');
    }

    public function test_errors(): void
    {
        throw new \RuntimeException('expected fixture error');
    }

    public function test_skipped(): void
    {
        self::markTestSkipped('expected fixture skip');
    }

    public function test_incomplete(): void
    {
        self::markTestIncomplete('expected fixture incomplete');
    }

    public function test_risky(): void
    {
        // Intentionally no assertions.
    }
}
