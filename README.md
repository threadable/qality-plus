# QAlity Plus for Laravel and PHPUnit

This package records Laravel, PHPUnit, and Pest test results and publishes
them to QAlity Plus and Jira from your CI/CD pipeline.

## Installation

```bash
composer require threadable/qality-plus
```

If your organization distributes the package through Private Packagist, see
[Private Packagist setup](docs/private-packagist.md) before running this
command.

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

The Jira project key is used to find existing QAlity test cases by name. The
default Jira test issue type is `QAlity Test`; override it with
`QALITY_JIRA_TEST_ISSUE_TYPE` when necessary.

For alternative authentication, explicit mappings, data providers, or other
configuration options, see [Advanced configuration](docs/advanced-configuration.md).

## Map tests to QAlity

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

When no name is provided, the package uses the PHPUnit method name or Pest test
description. If an issue key is already available, the case is treated as
existing and its name is not changed.

## CI/CD commands

Run the tests, then create or find the QAlity test cases on feature branches:

```bash
php artisan test
php artisan qality:create-test-cases --branch feature/PROJ-123-checkout
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

The create command first looks for an exact Jira test-case name in the
configured project. It creates a new case only when no matching case exists.
The publish command uses the same lookup when a result has no issue key, so the
feature pipeline does not need to transfer a mapping file to QA, UAT, or
production when test names remain stable.

Branches are expected to use `feature/KEY-123-description`,
`hotfix/KEY-123-description`, or `bugfix/KEY-123-description`. Use `--branch`
for CI systems where the branch cannot be detected automatically.

## Workflow

| Pipeline stage | Command | Result |
| --- | --- | --- |
| Feature branch | `qality:create-test-cases` | Finds existing cases or creates missing cases and links new cases to the branch work item |
| QA, UAT, production | `qality:publish` | Creates a QAlity Test Cycle and publishes passed, failed, and skipped executions |

Store QAlity and Jira credentials as secured CI/CD variables. The package does
not require a particular CI/CD provider.

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
