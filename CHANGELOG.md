# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.6] - 2026-09-28

### Fixed

- Correct the issue-link payload for `test_to_requirement`: the QAlity Test
  issue is sent as `inwardIssue` and the requirement as `outwardIssue`, so the
  configured `tests` and `is tested by` descriptions are displayed correctly.

## [1.0.5] - 2026-09-21

### Fixed

- Avoid linking QAlity test cases already marked with the created-case label to later feature branches.
- Link previously unlabeled existing cases once, then mark them to prevent repeat branch links.
- Fall back to namespace-safe Jira name searches for Pest-generated test names.
- Resolve test cases by deterministic Jira labels derived from result test IDs.
- Ignore PHPUnit event-facade sealing during Pest/ParaTest controller bootstraps.

### Documentation

- Document QAlity case creation, mapping, requirement-link, and feature-branch-link scenarios.

## [1.0.3] - 2026-09-08

### Fixed

- Report PHPUnit extension bootstrap and result-recording failures to stderr.

## [1.0.0] - 2026-08-11

### Added

- Automatic Jira name-based lookup for QAlity test cases.
- Creation and publishing commands with `storage/qality` defaults.
- Laravel and PHPUnit compatibility testing across the supported matrix.
- Laravel Boost guidelines and a `qality-plus-development` skill.
- Class-qualified fallback names for PHPUnit test cases.
- Configurable labels for newly created Jira test cases.
- Diagnostic logging for upstream requests, retries, command stages, and test-case import batches.
- Increased the default upstream HTTP timeout to 300 seconds for bulk imports.
- Disabled upstream retries by default to avoid duplicate non-idempotent requests.
- Batched Jira name lookups before importing or publishing test cases.
- Batched QAlity test-case imports and persisted mappings after each successful batch.
- Added connection-failure classification, response status codes, durations, and rate-limit details to diagnostics.
- Clarified Pest test metadata mapping and generated `class::method` test-case names in the documentation.
