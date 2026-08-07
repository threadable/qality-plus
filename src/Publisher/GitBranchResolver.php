<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class GitBranchResolver
{
    public function current(): string
    {
        $branch = trim((string) shell_exec('git branch --show-current 2>/dev/null'));

        if ($branch === '') {
            throw new PublisherException('Unable to determine the current Git branch; use --branch in detached-head or CI environments.');
        }

        return $branch;
    }
}
