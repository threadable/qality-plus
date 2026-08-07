<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

final class FailedResultSubscriber implements FailedSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(Failed $event): void
    {
        $this->collector->failed($event);
    }
}
