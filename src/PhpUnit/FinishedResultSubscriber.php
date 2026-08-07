<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class FinishedResultSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(Finished $event): void
    {
        $this->collector->finished($event);
    }
}
