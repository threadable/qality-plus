<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

final class PassedResultSubscriber implements PassedSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(Passed $event): void
    {
        $this->collector->passed($event);
    }
}
