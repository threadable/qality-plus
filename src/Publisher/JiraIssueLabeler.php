<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

interface JiraIssueLabeler
{
    public function addIssueLabel(string $issueKey, string $label): void;
}
