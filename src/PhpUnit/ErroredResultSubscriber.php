<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

final class ErroredResultSubscriber implements ErroredSubscriber
{
    public function __construct(private readonly TestResultCollector $collector) {}

    public function notify(Errored $event): void
    {
        $this->collector->errored($event);
    }
}
