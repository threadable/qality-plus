<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

final class SkippedResultSubscriber implements SkippedSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(Skipped $event): void
    {
        $this->collector->skipped($event);
    }
}
