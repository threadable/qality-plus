<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final readonly class PublishSummary
{
    public function __construct(
        public int $total,
        public int $published,
        public int $skipped,
        public int $linked,
        public ?string $cycleId,
        public bool $dryRun = false,
    ) {}
}
