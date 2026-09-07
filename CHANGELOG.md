# Changelog

All notable changes to Wireless are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [semantic versioning](https://semver.org).

Two lines are maintained in lockstep, and **the package major is the Livewire major**: Wireless 4
drives Livewire 4 (branch `main`), Wireless 3 drives Livewire 3 (branch `1.x`, released as `3.x`).
A feature ships to both on the same day; the source difference between them is `src/Lifecycle.php`,
which holds the hook signatures Livewire changed between majors, plus the constraints in
`composer.json`. Breaking changes to the package itself ride a minor within a line — the major is
spoken for.

## [Unreleased]

### Added
- `Wireless::actingAs($user)` — drives the next cycle as that user, from before the mount (which is
  where a component reads `Auth::user()`), and puts the previous one back when it finishes.
- `dispatch()` delivers an event to the component's own listeners; `mounted()` rounds out the
  chain; `throwValidationErrors()` undoes `keepValidationErrors()`.
- The outcome of a cycle — `errors()`, `redirect()`, `dispatched()`, `returned()` — stays readable
  after `finish()`, so `run()`'s driver is still worth holding on to.
- Every exception this package throws implements `Exceptions\WirelessException`.

### Changed
- **Breaking:** `dispatches()` is now `dispatched()`. One character separated "send an event in"
  from "read the events that went out".
- **Breaking:** `Exceptions\ComponentNotMounted` is now `ComponentNotMountedException`, matching
  Livewire's and Laravel's naming.
- **Breaking:** mounting a driver that is already mounted throws `ComponentAlreadyMountedException`
  instead of quietly replacing the component; reading `errors()`, `redirect()` or `dispatched()` on
  a driver that was never mounted throws instead of answering as though the cycle had succeeded.
- **Breaking:** `effects()` is gone. Effects are only written during `dehydrate`, which this cycle
  never runs, so it answered `[]` for every real cycle — `redirect()` and `dispatched()` read the
  place Livewire actually keeps them, and `returned()` hands back a file response as it is.
- **Breaking:** `returned()` on a driver that was never mounted throws instead of answering `null`,
  which is what a method returning nothing gives back.
- Support Laravel 13 alongside 12 (`illuminate/*: ^12.0|^13.0`), which is the range Livewire itself
  declares.
- `illuminate/validation`, `illuminate/auth` and `illuminate/contracts` are required explicitly:
  all three were used, and all three arrived by accident through Livewire or the framework.
- The Livewire constraint is `^4.3`, the version whose internals this package was verified against,
  rather than the whole major — it drives hooks that carry no BC promise.
- `Wireless::run()` takes the callback as its second argument when there are no mount parameters:
  `run(Counter::class, fn ($c) => …)`.
- The dist archive ships `src/`, the README, the changelog and the licence (`.gitattributes`
  export-ignores the rest).
- CI runs a `--prefer-lowest` leg on the floor PHP, PHPStan (level 6), `composer validate --strict`,
  the suite in random order, and a `continue-on-error` canary against Livewire's dev branch.
- A validation failure raised by an `updated` hook is carried to the next call instead of being
  wiped by it — `set()` filling a form field by field no longer loses `validateOnly()` failures.

### Fixed
- **A `flush-state` while a cycle was open poisoned `redirect` for the life of the process.**
  Livewire's own test harness flushes after every render; the cycle's snapshot was dropped with it,
  so every later `redirect()` in the process resolved a `Redirector` pinned to a destroyed
  component. The snapshot is handed back before the bookkeeping goes.
- **A teardown could hand the container Laravel's redirector while a component of the surrounding
  request still owned one**, silently sending that component's redirect nowhere. The application's
  redirector goes back only once no frame is left in flight.
- **Redirector frames are released by identity, not by position.** Drivers finished out of order
  renumbered the stack under one another, so a still-open cycle could lose its own frame.
- **`Livewire::flushState()` no longer runs from a destructor**, which fires at a moment nothing
  chose — including the middle of somebody else's render — and the "is Livewire busy?" question is
  asked again at teardown rather than only at mount.
- **A throwing `flush-state` listener no longer replaces the caller's own exception**: the flush is
  the last teardown step and is rescued like the rest of them.
- **Two cycles acting on one guard, or one cycle acting twice, put the application's user back.**
  The previous user is tracked per guard across cycles instead of per driver, so drivers finished in
  mount order no longer restore each other's acting user.
- **A component whose `mount()` threw left the container's `redirect` binding swapped and lazy
  loading disabled for the rest of the process.** The mount now happens inside the guarded region.
- The redirector is restored by rebinding rather than shadowing, so a container flush can no longer
  resurrect a Livewire `Redirector` pointing at a component that no longer exists; and the snapshot
  is shared, so two drivers finished in mount order no longer hand back Livewire's redirector as if
  it were Laravel's.
- `Livewire::flushState()` — a between-requests reset — no longer runs while Livewire is rendering
  into the response, where it would have dropped asset injection, blade keys and flash data.
- Livewire's component and redirector stacks are pushed and popped in balance, so anything the
  driven component mounts finds the right parent and a long-running worker stops accumulating.
- Lazy loading is disabled for the mount only, by scoping Livewire's own switch around it. The
  first attempt at this passed `lazy: false` in the mount payload, which Livewire writes onto
  matching public properties and forwards to `mount()` — corrupting a `$defer` property, a
  `mount($lazy = …)` default or a variadic `mount(...$args)`.
- Unwinding the redirector stack now rebinds each entry as Livewire's `dehydrate` would. Popping
  without rebinding left the container pointing at a finished component, so a component that drove
  another one and then redirected itself went nowhere.
- `set()` runs values through the synthesizers first, so a backed enum or a `Carbon` property takes
  a string the way a browser update does instead of aborting with a bare 419.
- A driver dropped without `finish()` gives the shared state back through its destructor, and the
  open-cycle registry resets with Livewire's own `flush-state` — one forgotten `finish()` used to
  stop every later cycle from restoring anything.
- `actingAs()` on a named guard forgets only that guard, and a cycle that never mounted still puts
  the previous user back.
- A call reports only the validation errors it is responsible for, and reports the validator's own
  exception: a stale bag no longer makes a later unrelated call throw, the same bad input twice no
  longer reads as success, and a method that called `addError()` before failing for another reason
  keeps its real exception.
- `actingAs()` restores "nobody was logged in" instead of raising a `TypeError` inside its own
  teardown — which used to abort the rest of it.
- The `exception` listener is removed even when a hook throws before the call, instead of
  accumulating one closure (and one pinned component) per failure.
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
