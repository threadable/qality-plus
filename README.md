# QAlity Plus for Laravel and PHPUnit

This package records Laravel, PHPUnit, and Pest test results and publishes
them to QAlity Plus and Jira from your CI/CD pipeline.

## Installation

```bash
composer require threadable/qality-plus
```

The package is auto-discovered by Laravel. Publish the configuration only when
you need to change a default:

```bash
php artisan vendor:publish --tag=qality-config
```

## Setup

Register the PHPUnit extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Threadable\QalityPlus\PhpUnit\QalityPlusExtension">
        <parameter name="directory" value="storage/qality"/>
        <parameter name="schema_version" value="1"/>
    </bootstrap>
</extensions>
```

Add these CI/CD variables:

```dotenv
QALITY_PLUS_API_TOKEN=...
QALITY_PLUS_PROJECT_ID=...

QALITY_JIRA_BASE_URL=https://example.atlassian.net
QALITY_JIRA_EMAIL=ci@example.com
QALITY_JIRA_API_TOKEN=...

# Jira project containing the QAlity test cases
QALITY_JIRA_PROJECT_KEY=QA
```

The Jira project key is used to find existing QAlity test cases by their
automatic identity label. Cases created before that label was available are
recovered through the exact-name fallback. The default Jira test issue type is
`QAlity Test`; override it with `QALITY_JIRA_TEST_ISSUE_TYPE` when necessary.

For alternative authentication, explicit mappings, data providers, or other
configuration options, see [Advanced configuration](docs/advanced-configuration.md).

## Map tests to QAlity

`QalityTestCase` is a PHP method attribute for PHPUnit tests. Pest tests use
closure syntax and are exposed to PHPUnit as generated test methods, so use an
explicit mapping file to attach issue and requirement metadata to them. The
mapping key must be the exact `test.id` written to the QAlity JSONL result; for
example:

```json
{
    "P\\Tests\\Unit\\Commands\\CleanTsmLogTest::__pest_evaluable_command": {
        "issue_key": "QA-123",
        "requirement_issue_key": "REQ-42"
    }
}
```

The generated Pest `class::method` value can vary with the test and project
namespace. Use the `test.id` from the result rather than the source filename
or source test description.

To use an existing QAlity test case, put the attribute on the individual test
method:

```php
use Threadable\QalityPlus\PhpUnit\QalityTestCase;

final class CheckoutTest extends TestCase
{
    #[QalityTestCase('QA-123', requirementIssueKey: 'REQ-42')]
    public function test_checkout_can_be_completed(): void
    {
        // ...
    }
}
```

To create a missing case with a friendly name, provide only a name:

```php
#[QalityTestCase(name: 'Customer can complete checkout')]
public function test_checkout_can_be_completed(): void
{
    // ...
}
```

If only a requirement is supplied on a PHPUnit test, the fully qualified class
and method name is used as the QAlity test-case name:

```php
#[QalityTestCase(requirementIssueKey: 'NDC-123')]
public function test_user_can_reset_their_password(): void
{
    // ...
}
```

When no name is provided, the package uses an explicit name first, then the
test's `class::method` value when one is available, and finally the test name or
ID. For Pest tests, this means the generated PHPUnit `class::method` value is
normally used; the Pest source description is not a dedicated fallback. If an
issue key is already available, the case is treated as existing and its name
is not changed.

`requirementIssueKey` is the source of truth for the Jira issue linked to that
test. It overrides the `--work-item` value. When it is omitted, the branch
work item is used as the fallback. This applies to both newly created and
already existing QAlity test cases. `linkType` and `linkDirection` can override
the configured defaults for an individual test.

## CI/CD commands

Run the tests, then create or find the QAlity test cases on feature branches:

```bash
php artisan test
php artisan qality:create-test-cases --branch feature/PROJ-123-checkout
```

When the pipeline does not expose a branch name, provide the Jira work item
directly. This skips branch detection and parsing:

```bash
php artisan qality:create-test-cases --work-item PROJ-123
```

On QA, UAT, and production deployments, run the tests and publish their
executions:

```bash
php artisan test
php artisan qality:publish
```

Both commands use `storage/qality` by default. A different result file or
directory can be supplied when needed. Use `--dry-run` to validate results
without calling QAlity or Jira:

```bash
php artisan qality:create-test-cases --branch feature/PROJ-123-checkout --dry-run
php artisan qality:publish --dry-run
```

If an upstream request fails, inspect the Laravel application log (normally
`storage/logs/laravel.log`) and search for `qality-plus`. Entries identify
whether the failure occurred in QAlity Plus or Jira, the configured host and
HTTP endpoint, the attempt number, response status, retry decision, and
command stage. Timeout, DNS, and other connection failures are identified
separately; HTTP failures include the returned status code. Request payloads
and authentication tokens are not logged.

The mapping file is optional when `QALITY_JIRA_PROJECT_KEY` is configured. Each
result gets a deterministic Jira label derived from its exact `test.id`:

```text
qality-auto-<sha256(test.id)>
```

The create command looks up that label first, then falls back to an exact Jira
test-case name for cases created before the label existed. A single match is
reused and labeled; multiple matches fail instead of selecting an arbitrary
case. Only tests with no label or name match are sent to the QAlity Plus import
endpoint. Newly created cases are labeled immediately, and resolved cases are
also labeled so later runs use the exact lookup.

The publish command uses the same label-first lookup when a result has no issue
key. Therefore, a pipeline may discard `.qality-test-map.json` after the first
successful create run; the label remains the stable identity for that emitted
test ID. If the emitted ID changes, such as during a PHPUnit-to-Pest
migration, the name fallback can reconcile the existing case once.

Before performing a Jira lookup, the package verifies the configured Jira
credentials with `/rest/api/3/myself` and verifies access to the configured
project with `/rest/api/3/project/{projectKey}`. A failed check stops the
command before any QAlity test-case import, so an authentication or project
permission problem is not treated as an empty lookup result.

Name lookups are sent to Jira in batches, then compared against exact issue
summaries locally. They are a migration fallback, not the normal identity
mechanism.

If `QALITY_JIRA_PROJECT_KEY` is not configured and the mapping file is absent,
unmapped tests are sent to QAlity Plus for import on every run and may create
duplicates. Also, `--dry-run` does not call Jira or QAlity, so its eligible
count does not include label or name lookup results.

Branches are expected to use `feature/KEY-123-description`,
`hotfix/KEY-123-description`, or `bugfix/KEY-123-description`. Use `--branch`
for CI systems where the branch cannot be detected automatically. Use
`--work-item` when the work item is already available separately; it takes
precedence over `--branch`.

## Workflow

| Pipeline stage | Command | Result |
| --- | --- | --- |
| Feature branch | `qality:create-test-cases` | Finds existing cases or creates missing cases and links each case to its annotated requirement, or to the branch work item when no requirement is annotated |
| QA, UAT, production | `qality:publish` | Creates a QAlity Test Cycle and publishes passed, failed, and skipped executions |

Store QAlity and Jira credentials as secured CI/CD variables. The package does
not require a particular CI/CD provider.

Created and resolved QAlity test cases receive their automatic identity label.
Newly created cases also receive `threadable-qality-plus` by default. Set
`QALITY_JIRA_CREATED_TEST_LABEL=` to disable that additional label; the
identity label is always managed by the package.

## Laravel Boost

This package includes Laravel Boost guidelines and a development skill. In a
Laravel application, install Boost as a development dependency and run its
installer to make the package guidance available to your AI coding agent:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development and pull-request
guidelines. At minimum, install the dependencies and run the full quality
check:

```bash
composer install
composer ci
```

Please include tests for behavior changes and open an issue before starting
large changes.

Security issues should be reported privately as described in
[SECURITY.md](SECURITY.md).

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
