# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

### Added
- `policy.json`, the register's usage policy in omnisense/contracts' schema (named in
  `composer.json`, `extra.omnisense.policy`), given by `usagePolicy()` (`DeclaresUsagePolicyInterface`):
  limits unknown (`rateLimits: null`); the rights unknown until the terms are read again. Requires
  `omnisense/contracts`.
- A 429 is thrown with `$status` 429: through the facade, the next calls wait for its `retryAfter`.
- PHPStan at level max, its baseline what the package had on 2026-11-10.

### Changed
- Requires `omnisense/contracts: ^1.0`, `omnisense/omnistate: ^1.0`: stable versions, so `composer require omnistate/matchid` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
- PHPStan at level max with an empty baseline, types only.

### Fixed
- An answer of another shape than matchID's API is an `UnavailableException` (the family's rule on
  a body that is not what it should be), where PHP threw a `TypeError`: `persons` not a list, a
  person not an object, a location not an object, a date, postcode or country given as a list.
- An unreachable matchID no longer carries the token: the `UnavailableException` wrapped the HTTP
  client's own exception, whose trace kept the request's headers in its arguments
  (`zend.exception_ignore_args` off). The previous is now a copy of it (the same class and code, the
  token masked in its message) and no trace of the chain keeps its arguments. Needs
  omnisense/omnistate's `Http\Redaction`.
