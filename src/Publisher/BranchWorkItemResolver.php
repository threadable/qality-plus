<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

final class BranchWorkItemResolver
{
    public function __construct(
        private readonly string $pattern = '/^(?:feature|hotfix|bugfix)\/(?<key>[A-Z][A-Z0-9]*-\d+)(?:[-\/].*)?$/i',
    ) {}

    public function resolve(string $branch): string
    {
        $branch = trim($branch);

        if ($branch === '') {
            throw new PublisherException('A non-empty Git branch name is required.');
        }

        $matches = [];

        if (@preg_match($this->pattern, $branch, $matches) !== 1) {
            throw new PublisherException(sprintf(
                'Branch [%s] does not match the configured feature/hotfix/bugfix work-item pattern.',
                $branch,
            ));
        }

        $key = $matches['key'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            throw new PublisherException('The configured branch pattern must provide a named [key] capture group.');
        }

        return strtoupper($key);
    }
}
