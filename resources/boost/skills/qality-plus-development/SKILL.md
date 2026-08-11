---
name: qality-plus-development
description: Configure, test, troubleshoot, and operate QAlity Plus in Laravel CI/CD workflows.
---

# QAlity Plus Development

Use this skill when working with the `threadable/qality-plus` package, its
PHPUnit/Pest integration, QAlity test-case mapping, Jira lookup, or CI/CD
publishing commands.

## Core workflow

1. Confirm the PHPUnit extension writes results to `storage/qality` unless the
   application explicitly configures another directory.
2. Put `QalityTestCase` attributes on individual test methods, never on test
   classes.
3. Use an explicit issue key for an existing QAlity test case.
4. Use `QalityTestCase(name: '...')` when a missing case should receive a
   stable, readable name.
5. On feature, hotfix, or bugfix branches, run
   `qality:create-test-cases --branch=...` after the tests.
6. On QA, UAT, or production publishing, run `qality:publish` after the tests.

## Mapping behavior

When a result has no issue key, QAlity Plus first uses an explicit mapping when
one is configured, then can resolve an exact test-case name through Jira when
`QALITY_JIRA_PROJECT_KEY` is configured. An exact single match is required;
ambiguous matches must be reported rather than selected arbitrarily.

The create command creates a missing QAlity case only when no matching case is
found. The publish command skips an unresolved result. Newly created cases can
be linked to the Jira work item parsed from the branch name.

## Safe verification

Use `--dry-run` before live calls:

```shell
php artisan qality:create-test-cases --branch=feature/PROJ-123-checkout --dry-run
php artisan qality:publish --dry-run
```

Never invent or expose QAlity Plus or Jira credentials. Do not use real
credentials in tests; use HTTP fixtures and contract assertions instead.

For explicit mapping files, bearer authentication, Jira link settings, and
existing-cycle publishing, consult the package advanced configuration guide.
