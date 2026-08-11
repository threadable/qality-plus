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
            'name' => null,
        ], $attribute->toArray());
    }

    public function test_it_supports_a_name_without_an_issue_key(): void
    {
        $attribute = new QalityTestCase(name: 'Customer can complete checkout');

        self::assertSame([
            'issue_key' => null,
            'requirement_issue_key' => null,
            'link_type' => null,
            'link_direction' => null,
            'name' => 'Customer can complete checkout',
        ], $attribute->toArray());
    }

    public function test_it_supports_a_requirement_without_an_issue_key_or_name(): void
    {
        $attribute = new QalityTestCase(requirementIssueKey: 'NDC-123');

        self::assertSame([
            'issue_key' => null,
            'requirement_issue_key' => 'NDC-123',
            'link_type' => null,
            'link_direction' => null,
            'name' => null,
        ], $attribute->toArray());
    }

    public function test_it_rejects_an_empty_attribute(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QalityTestCase;
    }
}
