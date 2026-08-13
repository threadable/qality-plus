# Advanced configuration

The main [README](../README.md) covers the normal setup. This page describes
optional features for projects that need explicit mappings or custom pipeline
behavior.

## Explicit mapping files

Automatic Jira identity lookup is normally enough, and the mapping file may be
absent or discarded between pipeline runs. Use an explicit mapping file when a
test needs fixed metadata, multiple QAlity cases already share an identity, or
pipelines must avoid Jira searches.

Register the file in `phpunit.xml`:

```xml
<parameter name="mapping_file" value=".qality-test-map.json"/>
```

The file uses the PHPUnit test ID as its key:

```json
{
    "P\\Tests\\Feature\\CheckoutTest::__pest_evaluable_it_can_checkout": {
        "issue_key": "QA-123",
        "requirement_issue_key": "REQ-42"
    }
}
```

Pest tests are exposed to PHPUnit as generated test methods. Their mapping key
must therefore be the exact `test.id` written to the QAlity JSONL result, such
as `P\\Tests\\Feature\\CheckoutTest::__pest_evaluable_it_can_checkout` in
the example above. The generated namespace and method depend on the project
and Pest version; do not derive the key from the source filename or Pest
description.

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
when the file is not present. Existing result files are not modified after a
mapping is created.

## Jira test-case lookup

Enable automatic Jira test-case lookup by configuring the Jira project that
contains the QAlity test cases:

```dotenv
QALITY_JIRA_PROJECT_KEY=QA
QALITY_JIRA_TEST_ISSUE_TYPE="QAlity Test"
```

The package derives a label from each result's exact `test.id`:

```text
qality-auto-<sha256(test.id)>
```

It searches within the configured project and issue type using that label:

- One match: its issue key is used.
- No match: the package tries the exact Jira summary as a migration fallback.
- Multiple matches: the command fails instead of selecting an arbitrary case.

If neither lookup finds a case, `qality:create-test-cases` imports one and
`qality:publish` skips the result. A successful create labels the case with
the generated identity so the next run does not need the name fallback.

The project key is a Jira project key and is separate from
`QALITY_PLUS_PROJECT_ID`, which identifies the QAlity project.

Lookups are batched into Jira search requests. The package compares returned
summaries exactly, so partial JQL matches are ignored. Only tests without a
matching label or summary are included in the QAlity Plus import. Newly
created and resolved cases receive the automatic identity label, while the
configured `QALITY_JIRA_CREATED_TEST_LABEL` remains an optional additional
label for newly created cases.

The identity label is based on the emitted framework test ID. A PHPUnit-to-Pest
migration can change that ID, so the first migrated run may use the exact-name
fallback to find and label the existing case. The package does not require a
custom Jira field or an `automation_key`.

The lookup uses the test-case name in this order:

1. `QalityTestCase(name: '...')`
2. The test's `class::method` value, including Pest's generated method value
3. The test name, then its ID when no class and method are available

If an issue key is supplied by an attribute or mapping file, no lookup is
performed and the existing QAlity case name is never updated.

The `QalityTestCase` attribute applies to PHPUnit test methods. Pest closure
tests cannot carry this attribute directly; use the explicit mapping file for
their issue, requirement, and link metadata. A custom `name` is currently
available through the PHPUnit attribute, not through a Pest mapping entry.

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
results. In stateless pipelines, the Jira identity label can reconcile those
earlier results on the next run; the exact-name fallback handles cases created
before labels were added.

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
