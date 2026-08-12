# Advanced configuration

The main [README](../README.md) covers the normal setup. This page describes
optional features for projects that need explicit mappings or custom pipeline
behavior.

## Explicit mapping files

Name-based Jira lookup is normally enough, and the mapping file may be absent
or discarded between pipeline runs. Use an explicit mapping file when a test
name can change, multiple QAlity cases have the same name, or pipelines must
avoid Jira searches.

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
file when the file is writable, but it can resolve cases again through Jira
name lookup when the file is not present. Existing result files are not
modified after a mapping is created.

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

Lookups are batched into Jira search requests. The package compares returned
summaries exactly, so partial JQL matches are ignored. Only tests without a
matching issue are included in the QAlity Plus import.

The lookup uses the test-case name in this order:

1. `QalityTestCase(name: '...')`
2. Fully qualified PHPUnit class and method name
3. Pest test description

If an issue key is supplied by an attribute or mapping file, no name lookup is
performed and the existing QAlity case name is never updated.

For `qality:create-test-cases`, the link target is resolved per test. An
attribute `requirementIssueKey` takes precedence over the `--work-item` or
branch work item. If no requirement is annotated, the work item is used as the
fallback. This applies to newly imported cases and existing cases resolved
from Jira or a mapping file; existing cases are not imported again, but their
expected link is reconciled.

The attribute can also override the global link settings for one test:

```php
#[QalityTestCase(
    requirementIssueKey: 'REQ-42',
    linkType: 'QAlity Test',
    linkDirection: 'test_to_requirement',
)]
public function test_checkout_can_be_completed(): void
{
    // ...
}
```

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
QALITY_IMPORT_BATCH_SIZE=50
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
QALITY_HTTP_TIMEOUT=300
QALITY_HTTP_RETRIES=0
QALITY_HTTP_RETRY_BACKOFF_MS=250
```

Missing test cases are imported in batches controlled by
`QALITY_IMPORT_BATCH_SIZE` (50 by default). The mapping file is written after
each successful batch, so a later batch failure does not discard earlier
results. In stateless pipelines, Jira name lookup can reconcile those earlier
results on the next run.

Upstream request failures are written to the Laravel default log channel with
the `qality-plus` prefix. The entries include the upstream (`QAlity Plus` or
`Jira`), configured host, HTTP method, endpoint, attempt number, elapsed time,
and retry decision. When an HTTP response exists, `status_code` records its
status, including `429` rate-limit responses and any `Retry-After` header. A
connection failure records `status_code: null`, `response_received: false`,
and a `failure_type` such as `timeout`, `dns`, or `connection`. Request
payloads and authentication tokens are not logged. The create and publish
commands also log the current processing stage and batch counts, which makes it
possible to distinguish a QAlity import failure from a Jira lookup, link, or
label failure.

`QALITY_JIRA_LINK_TYPE` is the Jira issue-link type name, not its outward or
inward description. For a Jira link type shown as
`QAlity Test | tests | is tested by`, use `QAlity Test` and
`test_to_requirement`.

`QALITY_JIRA_LINKS_ENABLED` controls optional requirement links while
publishing executions. The create command always honors an explicit
`requirementIssueKey` and uses its work-item fallback, regardless of this
publishing setting. Newly created test cases also receive
`QALITY_JIRA_CREATED_TEST_LABEL`; set it to an empty value to disable the
label.

Keep `QALITY_JIRA_LINKS_ENABLED=false` unless the pipeline should also create
requirement links while publishing executions. Enabling it changes Jira issue
relationships and requires the configured link type and Jira permissions.

Retries default to zero because QAlity imports, cycle creation, and execution
publishing use non-idempotent requests. Enable retries only when the upstream
operation is known to be safe to repeat.

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
