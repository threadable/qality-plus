<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Tests\Feature;

use Threadable\QalityPlus\Tests\TestCase;

final class BoostResourcesTest extends TestCase
{
    public function test_laravel_boost_resources_are_included_with_the_package(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileExists($root.'/resources/boost/guidelines/core.blade.php');
        self::assertFileExists($root.'/resources/boost/skills/qality-plus-development/SKILL.md');
    }
}
