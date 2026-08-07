<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpJiraClient extends HttpTransport implements JiraClient
{
    public function __construct(
        string $baseUrl,
        private readonly ?string $email,
        private readonly ?string $apiToken,
        private readonly ?string $bearerToken,
        int $timeout = 30,
        int $retries = 2,
        int $retryBackoffMs = 250,
    ) {
        parent::__construct($baseUrl, $timeout, $retries, $retryBackoffMs);
    }

    public function issue(string $issueKey): array
    {
        return $this->sendJira('GET', '/rest/api/3/issue/'.rawurlencode($issueKey).'?fields=issuelinks,project,issuetype');
    }

    public function issueLinkExists(string $testIssueKey, string $requirementIssueKey, string $linkType): bool
    {
        $issue = $this->issue($testIssueKey);
        $links = $issue['fields']['issuelinks'] ?? [];

        if (! is_array($links)) {
            return false;
        }

        foreach ($links as $link) {
            if (! is_array($link) || ($link['type']['name'] ?? null) !== $linkType) {
                continue;
            }

            $linkedIssues = [
                $link['outwardIssue']['key'] ?? null,
                $link['inwardIssue']['key'] ?? null,
            ];

            if (in_array($requirementIssueKey, $linkedIssues, true)) {
                return true;
            }
        }

        return false;
    }

    public function createIssueLink(
        string $testIssueKey,
        string $requirementIssueKey,
        string $linkType,
        string $direction = 'test_to_requirement',
    ): void {
        if (! in_array($direction, ['test_to_requirement', 'requirement_to_test'], true)) {
            throw new PublisherException('Jira issue-link direction must be test_to_requirement or requirement_to_test.');
        }

        $outward = $direction === 'test_to_requirement' ? $testIssueKey : $requirementIssueKey;
        $inward = $direction === 'test_to_requirement' ? $requirementIssueKey : $testIssueKey;

        $this->sendJira('POST', '/rest/api/3/issueLink', [
            'type' => ['name' => $linkType],
            'inwardIssue' => ['key' => $inward],
            'outwardIssue' => ['key' => $outward],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendJira(string $method, string $uri, array $payload = []): array
    {
        return $this->send(
            fn (): PendingRequest => $this->request(),
            $method,
            $uri,
            $payload === [] ? [] : ['json' => $payload],
        );
    }

    private function request(): PendingRequest
    {
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            return Http::acceptJson()->asJson()->withToken($this->bearerToken)->timeout($this->timeout);
        }

        if ($this->email !== null && $this->email !== '' && $this->apiToken !== null && $this->apiToken !== '') {
            return Http::acceptJson()->asJson()->withBasicAuth($this->email, $this->apiToken)->timeout($this->timeout);
        }

        throw new PublisherException('Jira credentials are not configured.');
    }
}
