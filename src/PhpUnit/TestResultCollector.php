<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\Skipped;
use Throwable;

final class TestResultCollector
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $results = [];

    /**
     * @var array<string, HRTime>
     */
    private array $startedAt = [];

    public function __construct(
        private readonly ResultWriter $writer,
        private readonly TestMetadataResolver $metadataResolver = new TestMetadataResolver,
    ) {}

    public function preparationStarted(PreparationStarted $event): void
    {
        $this->startedAt[$event->test()->id()] = $event->telemetryInfo()->time();
    }

    public function passed(Passed $event): void
    {
        $this->setOutcome($event->test(), 'passed');
    }

    public function failed(Failed $event): void
    {
        $this->setOutcome($event->test(), 'failed', $this->throwable($event->throwable()));
    }

    public function errored(Errored $event): void
    {
        $this->setOutcome($event->test(), 'error', $this->throwable($event->throwable()));
    }

    public function skipped(Skipped $event): void
    {
        $this->setOutcome($event->test(), 'skipped', ['message' => $event->message()]);
    }

    public function incomplete(MarkedIncomplete $event): void
    {
        $this->setOutcome($event->test(), 'incomplete', $this->throwable($event->throwable()));
    }

    public function risky(ConsideredRisky $event): void
    {
        $this->setOutcome($event->test(), 'risky', ['message' => $event->message()]);
    }

    public function finished(Finished $event): void
    {
        $id = $event->test()->id();
        $result = $this->results[$id] ?? [
            'status' => 'unknown',
        ];

        $result['test'] = $this->testDetails($event->test());
        $result['duration_ms'] = $this->durationMs($id, $event);
        $result['assertions'] = $event->numberOfAssertionsPerformed();
        $result['qality'] = $this->metadataResolver->resolve($event->test());

        try {
            $this->writer->write($result);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[qality-plus] '.$exception->getMessage().PHP_EOL);
        }

        unset($this->results[$id], $this->startedAt[$id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function testDetails(Test $test): array
    {
        $details = [
            'id' => $test->id(),
            'name' => $test->name(),
            'file' => $test->file(),
        ];

        if ($test->isTestMethod()) {
            $details['class'] = $test->className();
            $details['method'] = $test->methodName();
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function setOutcome(Test $test, string $status, array $details = []): void
    {
        $this->results[$test->id()] = [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function throwable(\PHPUnit\Event\Code\Throwable $throwable): array
    {
        return [
            'class' => $throwable->className(),
            'message' => $throwable->message(),
            'description' => $throwable->description(),
            'trace' => $throwable->stackTrace(),
        ];
    }

    private function durationMs(string $id, Finished $event): float
    {
        $start = $this->startedAt[$id] ?? null;

        if ($start === null) {
            return round($event->telemetryInfo()->durationSincePrevious()->asFloat() * 1000, 3);
        }

        return round($event->telemetryInfo()->time()->duration($start)->asFloat() * 1000, 3);
    }
}
