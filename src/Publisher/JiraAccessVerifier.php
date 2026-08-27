<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraAccessVerifier
{
    public function verifyAccess(string $projectKey): void;
}
