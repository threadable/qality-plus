<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class PreparationStartedResultSubscriber implements PreparationStartedSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(PreparationStarted $event): void
    {
        $this->collector->preparationStarted($event);
    }
}
