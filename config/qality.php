<?php

declare(strict_types=1);

return [
    'results' => [
        'directory' => env('QALITY_RESULTS_DIRECTORY', storage_path('qality')),
        'schema_version' => 1,
    ],

    'qality' => [
        'base_url' => env('QALITY_PLUS_BASE_URL', 'https://apps-qalityplus.soldevelo.com/api'),
        'token' => env('QALITY_PLUS_API_TOKEN'),
        'project_id' => env('QALITY_PLUS_PROJECT_ID'),
        'cycle_id' => env('QALITY_PLUS_CYCLE_ID'),
        'cycle_name' => env('QALITY_PLUS_CYCLE_NAME'),
        'cycle_comment' => env('QALITY_PLUS_CYCLE_COMMENT'),
    ],

    'create' => [
        'mapping_file' => env('QALITY_TEST_MAPPING_FILE', '.qality-test-map.json'),
        'branch_pattern' => env(
            'QALITY_BRANCH_PATTERN',
            '/^(?:feature|hotfix|bugfix)\/(?<key>[A-Z][A-Z0-9]*-\d+)(?:[-\/].*)?$/i',
        ),
    ],

    'jira' => [
        'base_url' => env('QALITY_JIRA_BASE_URL'),
        'email' => env('QALITY_JIRA_EMAIL'),
        'api_token' => env('QALITY_JIRA_API_TOKEN'),
        'bearer_token' => env('QALITY_JIRA_BEARER_TOKEN'),
    ],

    'publisher' => [
        'timeout' => (int) env('QALITY_HTTP_TIMEOUT', 30),
        'retries' => (int) env('QALITY_HTTP_RETRIES', 2),
        'retry_backoff_ms' => (int) env('QALITY_HTTP_RETRY_BACKOFF_MS', 250),
        'linking' => [
            'enabled' => (bool) env('QALITY_JIRA_LINKS_ENABLED', false),
            'type' => env('QALITY_JIRA_LINK_TYPE'),
            'direction' => env('QALITY_JIRA_LINK_DIRECTION', 'test_to_requirement'),
        ],
    ],
];
