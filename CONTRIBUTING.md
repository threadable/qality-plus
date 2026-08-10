# Contributing

Thank you for contributing to QAlity Plus for Laravel and PHPUnit.

## Development requirements

- PHP 8.3 or newer
- Composer 2

Install the development dependencies and run the checks:

```bash
composer install
composer ci
```

`composer ci` runs Laravel Pint and the PHPUnit test suite. Do not use real
QAlity Plus or Jira credentials in automated tests; HTTP behavior should be
covered with the existing fixtures and contract tests.

## Pull requests

Keep pull requests focused and explain the problem they solve. Include:

- a concise description of the behavior change;
- tests for new or changed behavior;
- documentation or configuration updates when the user-facing behavior changes;
- supported Laravel and PHP versions affected by the change.

Use the pull-request template and make sure all checks pass before requesting
review. Please open an issue before beginning a large change so the intended
design can be discussed first.

## Commit messages

Use short, descriptive, imperative commit messages with a conventional scope:

```text
feat: resolve QAlity cases by Jira name
test: cover ambiguous Jira test-case names
docs: clarify CI/CD setup
ci: test supported Laravel versions
```

Avoid messages such as `update`, `fix`, or `[CI]` without describing what
changed.

## Compatibility

Changes should preserve the supported PHP and Laravel version range unless the
pull request explicitly proposes a support change. Update the test matrix,
README, and changelog when compatibility changes.
