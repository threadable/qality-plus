<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

final class IncompleteResultSubscriber implements MarkedIncompleteSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(MarkedIncomplete $event): void
    {
        $this->collector->incomplete($event);
    }
}
