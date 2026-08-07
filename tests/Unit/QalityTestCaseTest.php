<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\PhpUnit\QalityTestCase;
use Threadable\QalityPlus\Tests\TestCase;

final class QalityTestCaseTest extends TestCase
{
    public function test_it_serializes_mapping_metadata(): void
    {
        $attribute = new QalityTestCase('QA-123', 'REQ-42', 'Tests', 'test_to_requirement');

        self::assertSame([
            'issue_key' => 'QA-123',
            'requirement_issue_key' => 'REQ-42',
            'link_type' => 'Tests',
            'link_direction' => 'test_to_requirement',
        ], $attribute->toArray());
    }
}
