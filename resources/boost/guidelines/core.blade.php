## QAlity Plus

QAlity Plus records Laravel, PHPUnit, and Pest test results as JSONL and
publishes them to QAlity Plus and Jira from CI/CD pipelines.

### Installation

Install the package in the Laravel application that runs the QAlity commands:

@verbatim
<code-snippet name="Install QAlity Plus" lang="shell">
composer require threadable/qality-plus
</code-snippet>
@endverbatim

The package is auto-discovered by Laravel.

### PHPUnit setup

Register the PHPUnit extension in `phpunit.xml`:

@verbatim
<code-snippet name="Register the PHPUnit extension" lang="xml">
<extensions>
    <bootstrap class="Threadable\QalityPlus\PhpUnit\QalityPlusExtension">
        <parameter name="directory" value="storage/qality"/>
        <parameter name="schema_version" value="1"/>
    </bootstrap>
</extensions>
</code-snippet>
@endverbatim

The default results directory is `storage/qality`.

### Map tests

`QalityTestCase` is a PHP method attribute for PHPUnit tests. Pest tests use
closure syntax and are exposed to PHPUnit as generated test methods, so use an
explicit mapping file for Pest metadata. The mapping key must exactly match the
Pest result's `test.id`, for example
`P\\Tests\\Unit\\Commands\\CleanTsmLogTest::__pest_evaluable_command`.

Place `QalityTestCase` on individual test methods. Do not place it on a test
class.

@verbatim
<code-snippet name="Map an existing QAlity test case" lang="php">
use Threadable\QalityPlus\PhpUnit\QalityTestCase;

#[QalityTestCase('QA-123', requirementIssueKey: 'REQ-42')]
public function test_checkout_can_be_completed(): void
{
    // ...
}
</code-snippet>
@endverbatim

To create a missing case with a stable name, use a name without an issue key:

@verbatim
<code-snippet name="Name a new QAlity test case" lang="php">
#[QalityTestCase(name: 'Customer can complete checkout')]
public function test_checkout_can_be_completed(): void
{
    // ...
}
</code-snippet>
@endverbatim

When only `requirementIssueKey` is supplied on a PHPUnit test, the fully
qualified class and method name is used as the QAlity test-case name. The
requirement key is used for the optional Jira requirement link.

When no name is provided, the package uses an explicit name first, then the
test's `class::method` value when available, and finally the test name or ID.
For Pest tests, the generated PHPUnit `class::method` value is normally used;
the Pest source description is not a dedicated fallback. If an issue key is
provided, the existing QAlity case is used and its name is not changed.

### Required CI/CD configuration

Configure these secured variables in the pipeline that calls the commands:

- `QALITY_PLUS_API_TOKEN`
- `QALITY_PLUS_PROJECT_ID`
- `QALITY_JIRA_BASE_URL`
- `QALITY_JIRA_EMAIL`
- `QALITY_JIRA_API_TOKEN`
- `QALITY_JIRA_PROJECT_KEY`

The default Jira test issue type is `QAlity Test`. Set
`QALITY_JIRA_TEST_ISSUE_TYPE` when the Jira project uses a different issue type.

### CI/CD workflow

On feature, hotfix, and bugfix branches, run tests and create or find cases:

@verbatim
<code-snippet name="Create QAlity test cases" lang="shell">
php artisan test
php artisan qality:create-test-cases --branch=feature/PROJ-123-checkout
</code-snippet>
@endverbatim

If the CI system provides the Jira work item separately, use `--work-item` to
skip branch detection and parsing:

@verbatim
<code-snippet name="Create cases with an explicit work item" lang="shell">
php artisan qality:create-test-cases --work-item=PROJ-123
</code-snippet>
@endverbatim

During QA, UAT, or production publishing, run tests and publish executions:

@verbatim
<code-snippet name="Publish QAlity executions" lang="shell">
php artisan test
php artisan qality:publish
</code-snippet>
@endverbatim

Both commands default to `storage/qality`. Use `--dry-run` to validate results
without making QAlity Plus or Jira requests.

Keep credentials in secured CI/CD variables. Never place tokens in test files,
fixtures, mappings, logs, or committed configuration.

Newly created QAlity test cases receive the Jira label
`threadable-qality-plus` by default. Set `QALITY_JIRA_CREATED_TEST_LABEL=` to
disable the label.
