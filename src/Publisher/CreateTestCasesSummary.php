<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final readonly class CreateTestCasesSummary
{
    public function __construct(
        public int $total,
        public int $eligible,
        public int $created,
        public int $linked,
        public int $skipped,
        public string $workItemKey,
        public bool $dryRun = false,
    ) {}
}
