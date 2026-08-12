# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

No unreleased changes.

## [1.0.0] - 2026-08-11

### Added

- Automatic Jira name-based lookup for QAlity test cases.
- Creation and publishing commands with `storage/qality` defaults.
- Laravel and PHPUnit compatibility testing across the supported matrix.
- Laravel Boost guidelines and a `qality-plus-development` skill.
- Class-qualified fallback names for PHPUnit test cases.
- Configurable labels for newly created Jira test cases.
- Diagnostic logging for upstream requests, retries, command stages, and test-case import batches.

### Fixed

- Support the Private Packagist username required by user and organization tokens.
