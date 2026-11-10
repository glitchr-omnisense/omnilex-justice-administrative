# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- A `recent` other than `lastModified` and `Date_Lecture` is an `InvalidConfigException` (an
  `\InvalidArgumentException` still), which `Registry::usable()` leaves out.
