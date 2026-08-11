<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\PhpUnit;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class QalityTestCase
{
    public function __construct(
        public ?string $issueKey = null,
        public ?string $requirementIssueKey = null,
        public ?string $linkType = null,
        public ?string $linkDirection = null,
        public ?string $name = null,
    ) {
        if (($issueKey === null || trim($issueKey) === '')
            && ($requirementIssueKey === null || trim($requirementIssueKey) === '')
            && ($name === null || trim($name) === '')) {
            throw new \InvalidArgumentException('A QAlity issue key, requirement issue key, or test-case name is required.');
        }
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'issue_key' => $this->issueKey,
            'requirement_issue_key' => $this->requirementIssueKey,
            'link_type' => $this->linkType,
            'link_direction' => $this->linkDirection,
            'name' => $this->name,
        ];
    }
}
