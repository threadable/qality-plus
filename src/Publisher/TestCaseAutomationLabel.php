<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class TestCaseAutomationLabel
{
    private const PREFIX = 'qality-auto-';

    public static function forTestId(string $testId): string
    {
        return self::PREFIX.hash('sha256', $testId);
    }
}
