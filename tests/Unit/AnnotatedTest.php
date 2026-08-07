<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\PhpUnit\QalityTestCase;
use Threadable\QalityPlus\Tests\TestCase;

final class AnnotatedTest extends TestCase
{
    #[QalityTestCase('QA-123', requirementIssueKey: 'REQ-42')]
    public function test_the_extension_can_resolve_method_metadata(): void
    {
        self::assertTrue(true);
    }
}
