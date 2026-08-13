<?php

declare(strict_types=1);

namespace Threadable\QalityPlus\Publisher;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpJiraClient extends HttpTransport implements JiraBulkTestCaseLookup, JiraClient, JiraIssueLabeler, JiraTestCaseLookup
{
    private const NAME_LOOKUP_BATCH_SIZE = 50;

    public function __construct(
        string $baseUrl,
        private readonly ?string $email,
        private readonly ?string $apiToken,
        private readonly ?string $bearerToken,
        int $timeout = 300,
        int $retries = 0,
        int $retryBackoffMs = 250,
    ) {
        parent::__construct($baseUrl, $timeout, $retries, $retryBackoffMs, 'Jira');
    }

    public function issue(string $issueKey): array
    {
        return $this->sendJira('GET', '/rest/api/3/issue/'.rawurlencode($issueKey).'?fields=issuelinks,project,issuetype');
    }

    /**
     * @return list<string>
     */
    public function findTestCaseKeysByName(string $name, string $projectKey, string $issueType): array
    {
        $payload = $this->sendJira('POST', '/rest/api/3/search/jql', [
            'jql' => sprintf(
                'project = %s AND issuetype = %s AND summary ~ %s',
                $this->jqlString($projectKey),
                $this->jqlString($issueType),
                $this->jqlString($name),
            ),
            'fields' => ['summary'],
            'maxResults' => 50,
        ]);
        $issues = $payload['issues'] ?? [];

        if (! is_array($issues)) {
            return [];
        }

        $keys = [];

        foreach ($issues as $issue) {
            if (! is_array($issue) || ($issue['fields']['summary'] ?? null) !== $name) {
                continue;
            }

            $key = $issue['key'] ?? null;

            if (is_string($key) && trim($key) !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    public function findTestCaseKeysByNames(array $names, string $projectKey, string $issueType): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $names),
            static fn (string $name): bool => $name !== '',
        )));
        $matches = [];

        foreach (array_chunk($names, self::NAME_LOOKUP_BATCH_SIZE) as $batch) {
            $payload = $this->sendJira('POST', '/rest/api/3/search/jql', [
                'jql' => sprintf(
                    'project = %s AND issuetype = %s AND summary in (%s)',
                    $this->jqlString($projectKey),
                    $this->jqlString($issueType),
                    implode(', ', array_map(fn (string $name): string => $this->jqlString($name), $batch)),
                ),
                'fields' => ['summary'],
                'maxResults' => self::NAME_LOOKUP_BATCH_SIZE,
            ]);
            $this->collectExactNameMatches($matches, $payload['issues'] ?? [], $batch);

            $unresolved = array_values(array_filter(
                $batch,
                static fn (string $name): bool => ! isset($matches[$name]),
            ));

            if ($unresolved === []) {
                continue;
            }

            $fallbackClauses = array_values(array_unique(array_map(
                fn (string $name): string => 'summary ~ '.$this->jqlString($this->searchTerm($name)),
                $unresolved,
            )));

            $fallbackPayload = $this->sendJira('POST', '/rest/api/3/search/jql', [
                'jql' => sprintf(
                    'project = %s AND issuetype = %s AND (%s)',
                    $this->jqlString($projectKey),
                    $this->jqlString($issueType),
                    implode(' OR ', $fallbackClauses),
                ),
                'fields' => ['summary'],
                'maxResults' => self::NAME_LOOKUP_BATCH_SIZE,
            ]);

            $this->collectExactNameMatches($matches, $fallbackPayload['issues'] ?? [], $unresolved);
        }

        return $matches;
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

    public function addIssueLabel(string $issueKey, string $label): void
    {
        $this->sendJira('PUT', '/rest/api/3/issue/'.rawurlencode($issueKey), [
            'update' => [
                'labels' => [['add' => $label]],
            ],
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

    private function jqlString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * Jira text search is more reliable for a namespace-free fragment than
     * for a backslash-heavy Pest class and generated method name. The exact
     * summary comparison still happens locally in this client.
     */
    private function searchTerm(string $name): string
    {
        $separator = strrpos($name, '::');

        if ($separator === false) {
            return $name;
        }

        $class = substr($name, 0, $separator);
        $namespaceSeparator = strrpos($class, '\\');

        return $namespaceSeparator === false
            ? $class
            : substr($class, $namespaceSeparator + 1);
    }

    /**
     * @param  array<string, list<string>>  $matches
     * @param  list<string>  $requestedNames
     */
    private function collectExactNameMatches(array &$matches, mixed $issues, array $requestedNames): void
    {
        if (! is_array($issues)) {
            return;
        }

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $summary = $issue['fields']['summary'] ?? null;
            $key = $issue['key'] ?? null;

            if (! is_string($summary) || ! in_array($summary, $requestedNames, true) || ! is_string($key) || trim($key) === '') {
                continue;
            }

            $matches[$summary][] = $key;
            $matches[$summary] = array_values(array_unique($matches[$summary]));
        }
    }
}
