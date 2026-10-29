# Changelog

## [Unreleased]

### Fixed

- A `recent` other than `lastModified` and `Date_Lecture` is an `InvalidConfigException` (an
  `\InvalidArgumentException` still), which `Registry::usable()` leaves out.
