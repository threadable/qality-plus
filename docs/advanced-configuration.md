# Advanced configuration

The main [README](../README.md) covers the normal setup. This page describes
optional features for projects that need explicit mappings or custom pipeline
behavior.

## Explicit mapping files

Name-based Jira lookup is normally enough. Use an explicit mapping file when a
test name can change, multiple QAlity cases have the same name, or pipelines
must avoid Jira searches.

Register the file in `phpunit.xml`:

```xml
<parameter name="mapping_file" value=".qality-test-map.json"/>
```

The file uses the PHPUnit test ID as its key:

```json
{
    "Pest\\Tests\\Feature\\CheckoutTest::it_can_checkout": {
        "issue_key": "QA-123",
        "requirement_issue_key": "REQ-42"
    }
}
```

Data-provider tests may be mapped using the full generated ID or the base
`Class::method` ID:

```json
{
    "Tests\\Feature\\CheckoutTest::test_checkout#valid-card": {
        "issue_key": "QA-123"
    },
    "Tests\\Feature\\CheckoutTest::test_checkout": {
        "issue_key": "QA-124"
    }
}
```

The exact ID takes precedence over the base method ID. The mapping file itself
does not infer cases from names.

`qality:create-test-cases` writes returned QAlity issue keys to the mapping
file. If the mapping file is used across pipelines, commit it or transfer it as
a CI/CD artifact. Existing result files are not modified after a mapping is
created.

## Jira name-based lookup

Enable name-based lookup by configuring the Jira project that contains the
QAlity test cases:

```dotenv
QALITY_JIRA_PROJECT_KEY=QA
QALITY_JIRA_TEST_ISSUE_TYPE="QAlity Test"
```

The package searches within that project and issue type, then compares the
returned Jira summaries exactly:

- One match: its issue key is used.
- No match: `qality:create-test-cases` creates a new case; `qality:publish` skips the result.
- Multiple matches: the command fails instead of selecting an arbitrary case.

The project key is a Jira project key and is separate from
`QALITY_PLUS_PROJECT_ID`, which identifies the QAlity project.

The lookup uses the test-case name in this order:

1. `QalityTestCase(name: '...')`
2. Fully qualified PHPUnit class and method name
3. Pest test description

If an issue key is supplied by an attribute or mapping file, no name lookup is
performed and the existing QAlity case name is never updated.

## Jira authentication alternatives

The default Jira Cloud setup uses an email address and API token:

```dotenv
QALITY_JIRA_BASE_URL=https://example.atlassian.net
QALITY_JIRA_EMAIL=ci@example.com
QALITY_JIRA_API_TOKEN=...
```

You can instead provide a bearer token:

```dotenv
QALITY_JIRA_BEARER_TOKEN=...
```

The bearer token takes precedence over email/API-token authentication. For Jira
Cloud OAuth 2.0, use the Atlassian API gateway URL for the site, for example
`https://api.atlassian.com/ex/jira/<cloud-id>`. The package accepts an issued
token; it does not obtain or refresh OAuth tokens.

## Optional settings

```dotenv
QALITY_PLUS_BASE_URL=https://apps-qalityplus.soldevelo.com/api
QALITY_PLUS_CYCLE_ID=
QALITY_PLUS_CYCLE_NAME=
QALITY_PLUS_CYCLE_COMMENT=
QALITY_RESULTS_DIRECTORY=storage/qality
QALITY_TEST_MAPPING_FILE=.qality-test-map.json
QALITY_BRANCH_PATTERN=/^(?:feature|hotfix|bugfix)\/(?<key>[A-Z][A-Z0-9]*-\d+)(?:[-\/].*)?$/i
QALITY_JIRA_LINKS_ENABLED=false
QALITY_JIRA_LINK_TYPE="QAlity Test"
QALITY_JIRA_LINK_DIRECTION=test_to_requirement
QALITY_JIRA_CREATED_TEST_LABEL=threadable-qality-plus
QALITY_HTTP_TIMEOUT=30
QALITY_HTTP_RETRIES=2
QALITY_HTTP_RETRY_BACKOFF_MS=250
```

`QALITY_JIRA_LINK_TYPE` is the Jira issue-link type name, not its outward or
inward description. For a Jira link type shown as
`QAlity Test | tests | is tested by`, use `QAlity Test` and
`test_to_requirement`.

`QALITY_JIRA_LINKS_ENABLED` controls optional requirement links during
publishing. Newly created test cases are always linked to the branch work item.
Newly created test cases also receive `QALITY_JIRA_CREATED_TEST_LABEL`; set it
to an empty value to disable the label.

The test-case import does not set the Jira workflow status. The status assigned
by the Jira project workflow remains in effect; execution outcomes are
published later through QAlity test executions.

## Existing-cycle publishing

Use an existing cycle instead of creating a new one:

```bash
php artisan qality:publish --cycle-id=12345
```

Alternatively set `QALITY_PLUS_CYCLE_ID` in the environment. If neither is
provided, the publisher creates a new QAlity Test Cycle.
