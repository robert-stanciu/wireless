# Changelog

All notable changes to Wireless are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [semantic versioning](https://semver.org).

Two lines are maintained in lockstep — `1.x` for Livewire 3, `2.x` for Livewire 4. A feature ships
to both on the same day, and the only source difference between them is `src/Lifecycle.php`, which
holds the hook signatures Livewire changed between the majors.

## [Unreleased]

### Changed
- Support Laravel 13 alongside 12 (`illuminate/*: ^12.0|^13.0`), which is the range Livewire itself
  declares.
- `illuminate/validation` is required explicitly — `ValidationException` was always thrown, but the
  dependency came in only because Livewire happened to pull it. `illuminate/contracts` is dropped;
  nothing imported it.
- `Wireless::run()`'s `$params` now defaults to `[]`.
- CI runs a `--prefer-lowest` leg, so the declared floors are actually proven, and lints in its own
  job (`composer validate --strict` + `pint --test`) instead of once per PHP version.
- The dist archive ships `src/` and the licence only (`.gitattributes` export-ignores the rest).

### Fixed
- IDE files (`.idea/`) are no longer tracked, and no longer shipped to consumers.

## [1.1.0] · [2.1.0] — 2026-09-07

### Added
- `dispatches()`: the events the component sent to the browser, in the shape a response would have
  carried them. They live in Livewire's store until `dehydrate` — a hook this cycle must never run —
  so reading the context effects came back empty.
- The test suite that gives the package its name in CI: 68 tests driving real Livewire on both
  majors, covering mount parameters and defaults, aliases, lazy components, container injection,
  form objects, nested arrays and collections through the synthesizers, `updated()` hooks, locked
  and computed properties, listeners, validation from rules / form objects / `addError()`,
  redirects, teardown after an exception, and nested and concurrent cycles.

## [1.0.0] · [2.0.0] — 2026-09-07

### Added
- `Wireless::run()` — mount a component, hand it to a callback, tear the cycle down afterwards even
  when the callback throws.
- `Wireless::component()` — the fluent driver, for a component that has to stay around.
- `ComponentDriver`: `mount()`, `set()`, `get()`, `call()`, `returned()`, `errors()`,
  `keepValidationErrors()`, `redirect()`, `effects()`, `instance()`, `finish()`.

[Unreleased]: https://github.com/robert-stanciu/wireless/compare/v2.1.0...main
[2.1.0]: https://github.com/robert-stanciu/wireless/releases/tag/v2.1.0
[1.1.0]: https://github.com/robert-stanciu/wireless/releases/tag/v1.1.0
[2.0.0]: https://github.com/robert-stanciu/wireless/releases/tag/v2.0.0
[1.0.0]: https://github.com/robert-stanciu/wireless/releases/tag/v1.0.0
