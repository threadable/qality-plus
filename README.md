# QAlity Plus for Laravel and PHPUnit

This package records PHPUnit outcomes as versioned JSONL and publishes those
records to QAlity Plus from CI. It uses native QAlity and Jira APIs

## Installation

```bash
composer require threadable/qality-plus
```

Publish the package configuration when local overrides are needed:

```bash
php artisan vendor:publish --tag=qality-config
```

## PHPUnit extension

Register the Composer-loaded extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Threadable\QalityPlus\PhpUnit\QalityPlusExtension">
        <parameter name="directory" value="storage/qality"/>
        <parameter name="schema_version" value="1"/>
    </bootstrap>
</extensions>
```

The extension adds no output replacement, so existing PHPUnit output and JUnit
logging continue to work. Each completed test is appended to a file named like
`qality-results.v1.<run-id>.jsonl`.

Map a PHPUnit test to an existing QAlity test case with an attribute:

```php
use Threadable\QalityPlus\PhpUnit\QalityTestCase;

#[QalityTestCase('QA-123', requirementIssueKey: 'REQ-42')]
final class CheckoutTest extends TestCase
{
    public function test_checkout_can_be_completed(): void
    {
        // ...
    }
}
```

The attribute may also be placed on an individual test method. Tests without
the attribute are recorded but skipped by the publisher.

Pest tests can use an explicit JSON mapping file because Pest generates the
underlying PHPUnit test method. Register it as an extension parameter:

```xml
<parameter name="mapping_file" value=".qality-test-map.json"/>
```

The file is keyed by the PHPUnit event test ID:

```json
{
    "Pest\\Tests\\Feature\\CheckoutTest::it_can_checkout": {
        "issue_key": "QA-123",
        "requirement_issue_key": "REQ-42"
    }
}
```

Data-provider IDs may be mapped either exactly or by their base
`Class::method` ID. Name-based inference is not performed.

## CI publisher

Configure these required `.env` variables for live command execution:

```dotenv
# QAlity Plus
QALITY_PLUS_API_TOKEN=...
QALITY_PLUS_PROJECT_ID=...

# Jira: use either email + API token or a bearer token
QALITY_JIRA_BASE_URL=https://example.atlassian.net
QALITY_JIRA_EMAIL=ci@example.com
QALITY_JIRA_API_TOKEN=...
# QALITY_JIRA_BEARER_TOKEN=...
```

`QALITY_PLUS_PROJECT_ID` is required by `qality:create-test-cases` and by
`qality:publish` when it creates a new cycle. It can be omitted for publishing
when an existing cycle is supplied with `--cycle-id` or
`QALITY_PLUS_CYCLE_ID`. Jira credentials are required by both commands because
they read and create Jira issue links. The `--dry-run` variants only validate
local JSONL data and do not require API credentials.

Optional settings and their defaults are:

```dotenv
QALITY_PLUS_BASE_URL=https://apps-qalityplus.soldevelo.com/api
QALITY_RESULTS_DIRECTORY=storage/qality
QALITY_TEST_MAPPING_FILE=.qality-test-map.json
QALITY_JIRA_LINKS_ENABLED=false
QALITY_JIRA_LINK_TYPE="QAlity Test"
QALITY_JIRA_LINK_DIRECTION=test_to_requirement
```

`QALITY_JIRA_LINK_TYPE` is the Jira issue-link type **Name**, not its outward
or inward description. For a Jira link configuration shown as
`QAlity Test | tests | is tested by`, use `QAlity Test` as the type and
`test_to_requirement` to make the requirement display `is tested by` the
QAlity Test.

Publish the default `storage/qality` results, or provide a result file/directory:

```bash
php artisan qality:publish
php artisan qality:publish storage/qality
php artisan qality:publish storage/qality/run.jsonl --cycle-id 12345
php artisan qality:publish --dry-run
```

The publisher creates a new cycle when no cycle ID is supplied. It resolves
mapped Jira issues, adds them to the cycle, and creates native QAlity
executions. Transient HTTP failures are retried with bounded exponential
backoff; unresolved failures return a non-zero command status.

## Create missing test cases from a branch

Create QAlity test cases for JSONL results that do not already have a QAlity
mapping, then link the new test-case issues to the Jira work item in the
current branch:

```bash
php artisan qality:create-test-cases --branch feature/PROJ-123-checkout
php artisan qality:create-test-cases storage/qality/run.jsonl --branch feature/PROJ-123-checkout
php artisan qality:create-test-cases --dry-run
```

The result path is optional for both commands and defaults to the configured
`QALITY_RESULTS_DIRECTORY` value, which defaults to `storage/qality`.

Branches must match `feature/KEY-123-description`, `hotfix/KEY-123-description`,
or `bugfix/KEY-123-description` by default. Use `--branch` for detached-head
CI jobs. The work-item pattern can be changed with `QALITY_BRANCH_PATTERN`;
custom patterns must provide a named `key` capture group.

The command uses the configured `QALITY_PLUS_PROJECT_ID` and Jira link settings.
The default Jira link type is `QAlity Test`; override it with
`QALITY_JIRA_LINK_TYPE` when the Jira instance uses a different link type. It
records created case keys in `.qality-test-map.json` so rerunning the command
does not create duplicates. Override that location with
`QALITY_TEST_MAPPING_FILE` or `--mapping-file`. The command fails before making
API calls when the branch cannot provide a work-item key.

## Recommended CI/CD workflow

The intended deployment flow separates test-case creation from test execution
publishing:

| Pipeline stage | QAlity Plus action |
| --- | --- |
| `feature/*` branch | Record JSONL, create missing test cases, and link them to the branch work item |
| QA, UAT, or production deployment | Record JSONL, create a new QAlity Test Cycle, and publish executions for existing cases |

Configure the PHPUnit extension with the persisted mapping file in every stage
after feature cases have been accepted:

```xml
<parameter name="mapping_file" value=".qality-test-map.json"/>
```

The mapping file must be committed with the test changes or transferred to the
later pipeline stage through an approved artifact or repository mechanism.
The create command does not modify old JSONL records, and `qality:publish`
will skip records whose current run does not contain an `issue_key`.

A feature-branch step can pass its CI provider’s branch variable directly:

```bash
composer install --prefer-dist --no-interaction
php artisan test
php artisan qality:create-test-cases --branch "$CI_BRANCH_NAME"
```

Replace `CI_BRANCH_NAME` with the branch variable provided by the selected
CI/CD platform. Persist `storage/qality` and `.qality-test-map.json` using the
platform’s artifacts, cache, workspace, repository, or deployment handoff
mechanism as appropriate.

QA, UAT, and production deployment steps should run the test suite with the
persisted mapping file and then publish the results without `--cycle-id` when a
new cycle is required:

```bash
composer install --prefer-dist --no-interaction
php artisan test
php artisan qality:publish
```

Use the same deployment-stage commands for QA, UAT, and production. Passing
`--cycle-id` is available when a pipeline must publish into an existing cycle.
Store QAlity and Jira credentials as secured CI/CD variables or secret values.
The package’s own CI workflow validates library compatibility; each consuming
application can integrate these commands into its preferred CI/CD platform.
