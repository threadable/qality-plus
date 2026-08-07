<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Unit;

use Threadable\QalityPlus\Publisher\BranchWorkItemResolver;
use Threadable\QalityPlus\Publisher\PublisherException;
use Threadable\QalityPlus\Tests\TestCase;

final class BranchWorkItemResolverTest extends TestCase
{
    public function test_it_extracts_a_work_item_from_supported_branch_names(): void
    {
        $resolver = new BranchWorkItemResolver;

        self::assertSame('PROJ-123', $resolver->resolve('feature/PROJ-123-checkout'));
        self::assertSame('PROJ-456', $resolver->resolve('hotfix/PROJ-456'));
        self::assertSame('PROJ-789', $resolver->resolve('bugfix/proj-789-payment'));
    }

    public function test_it_rejects_branches_without_a_supported_work_item(): void
    {
        $this->expectException(PublisherException::class);

        (new BranchWorkItemResolver)->resolve('chore/update-dependencies');
    }

    public function test_custom_patterns_use_the_named_key_capture(): void
    {
        $resolver = new BranchWorkItemResolver('/^work\/(?<key>[A-Z]+-\d+)$/');

        self::assertSame('ABC-12', $resolver->resolve('work/ABC-12'));
    }
}
