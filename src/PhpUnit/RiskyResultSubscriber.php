<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;

final class RiskyResultSubscriber implements ConsideredRiskySubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(ConsideredRisky $event): void
    {
        $this->collector->risky($event);
    }
}
