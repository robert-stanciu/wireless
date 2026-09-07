# Wireless

**Drive Livewire components from PHP — mount, set, call — without an HTTP round trip.**

A Livewire component is an ordinary class, but the parts worth reusing only happen inside
Livewire's pipeline: `mount()`, `updatedX()` hooks, form objects, computed properties, validation.
Instantiating the class yourself skips all of it. `Livewire::test()` runs the real thing, but it
belongs to your test suite, not to a job or an artisan command.

Wireless runs that same pipeline in-process, so anything on the server can use a component the way
the browser does.

```php
use RobertStanciu\Wireless\Facades\Wireless;

$invoice = Wireless::run(CreateInvoice::class, ['company' => $company], fn ($form) => $form
    ->set('customer_id', $customer->id)
    ->set('lines.0.total', 250)
    ->call('save')
    ->returned());
```

## Installation

Not on Packagist yet — point Composer at the repository, then pick the line that matches your
Livewire:

```bash
composer config repositories.wireless vcs https://github.com/robert-stanciu/wireless

composer require robert-stanciu/wireless:^4.0   # Livewire 4
composer require robert-stanciu/wireless:^3.0   # Livewire 3
```

| Wireless | Livewire | Laravel | PHP |
| --- | --- | --- | --- |
| `^4.0` | `^4.3` | 12, 13 | 8.2+ |
| `^3.0` | `^3.6` | 12 | 8.2+ |

**The package major is the Livewire major** — Wireless 4 drives Livewire 4, Wireless 3 drives
Livewire 3. You are reading the `main` line (Livewire 4). The two lines exist because Livewire moved
the `mount` and `call` hook signatures between majors; the package API is the same on both from
v3.0.0 / v4.0.0 on, and features ship to both lines together.

**Which one do I want?**

- Asserting on rendered HTML, `wire:` directives or a whole request → `Livewire::test()`, or a real
  request.
- Running a component's *behaviour* outside a browser — a job, a command, an import → Wireless.
- Both, in a test suite? `Livewire::test()` is still the right tool there. Wireless is for
  production code paths.

## Why

- **Import a spreadsheet through the same form the user fills in.** One validated row per component
  cycle — no second implementation of the rules to keep in sync.
- **Reuse a Livewire form in an artisan command or a queued job**, including its form objects.
- **Call a component from another component** without a nested-component dance.
- **Seed realistic data** by driving the real create form instead of hand-writing model factories
  that bypass every domain rule.

## Usage

### `run()` — mount, use, tear down

```php
Wireless::run(Counter::class, ['start' => 5], function ($counter) {
    $counter->call('increment');

    return $counter->get('count'); // 6
});
```

The callback gets a `ComponentDriver`. The cycle is always torn down afterwards, exception or not.

### The driver

```php
$driver = Wireless::component(Signup::class)->mount(['plan' => 'pro']);

$driver->set('email', 'someone@example.com');   // goes through Livewire: updatedEmail() fires
$driver->set(['name' => 'Ada', 'terms' => true]); // several at once
$driver->get('email');                           // dotted paths work: 'form.lines.0.total'

$driver->call('save', $argument);                // hooks, wire:model state, validation
$driver->returned();                             // what save() returned
$driver->dispatch('order-placed', reference: 'INV-1');  // deliver an event to its listeners
                                                 // (a call like any other: returned() is the listener's)

$driver->errors();                               // the component's MessageBag
$driver->redirect();                             // '/dashboard', or null
$driver->dispatched();                           // [['name' => 'saved', 'params' => [...]], ...]
$driver->instance();                             // the component itself
$driver->mounted();                              // is a cycle open?

$driver->finish();                               // destroy hooks, and the shared state Livewire
                                                 // swapped (idempotent)
```

Outside `run()`, **`finish()` is yours to call** — until then Livewire's per-request state stays
loaded and the container keeps Livewire's redirector.

**The outcome outlives the component.** `run()` returns whatever the callback returned, but the
driver it built is finished by then — so `errors()`, `redirect()`, `dispatched()` and `returned()`
keep answering from the finished cycle, while `get()`, `set()`, `call()` and `instance()` throw
`ComponentNotMountedException`. Reading them on a driver that was never mounted throws too: an
empty bag would otherwise read exactly like a clean save.

```php
$driver = Wireless::component(ImportRow::class);

foreach ($rows as $row) {
    try {
        $driver->mount()->set($row)->call('save');
    } catch (ValidationException) {
        $failures[$driver->get('reference')] = $driver->errors()->all();
    } finally {
        $driver->finish();   // and the next mount() starts from a clean outcome
    }
}
```

Everything this package throws itself implements `RobertStanciu\Wireless\Exceptions\WirelessException`
(`ComponentNotMountedException`, `ComponentAlreadyMountedException`, `MissingCallbackException`), so
`catch (WirelessException)` separates "the driver was used wrong" from "the component failed".

### Acting as a user

A component reads `Auth::user()` during `mount()`, so the user has to be set before the cycle
starts — that is on the manager, not on the driver:

```php
Wireless::actingAs($user)->run(CreateInvoice::class, ['company' => $company], fn ($form) => $form
    ->set('customer_id', $customer->id)
    ->call('save'));
```

Whoever was authenticated before is put back when the cycle finishes, so a loop cannot leak one
row's user into the next. On the fluent path the driver takes it directly —
`Wireless::component(X::class)->actingAs($user)->mount()` — as long as it comes before `mount()`.

### Validation

A call whose validation fails throws `Illuminate\Validation\ValidationException`, so a caller that
wraps rows in a try/catch reads naturally:

```php
foreach ($rows as $row) {
    try {
        Wireless::run(ImportRow::class, [], fn ($form) => $form->set($row)->call('save'));
    } catch (ValidationException $e) {
        $failures[] = [$row, $e->errors()];
    }
}
```

Prefer to inspect the bag instead? `->keepValidationErrors()` turns the throw off for that driver
(and `->throwValidationErrors()` turns it back on). The exception you catch is the validator's own,
so `$e->validator->failed()` still tells you which rules failed — unless the component only called
`addError()`, in which case there was no exception to keep and you get one built from the bag.

## What it does not do

- **No rendering.** Wireless drives behaviour, not Blade: `render()` never runs, so `rendering()` /
  `rendered()` hooks, `#[Renderless]` and any state a component builds *inside* `render()` are not
  part of the picture — and a broken view will not be caught here. Reach for `Livewire::test()`
  (assertions against HTML) or a real request when you need the view.
- **No client round trip.** There is no snapshot, no checksum, no `wire:navigate`. Nothing is
  serialised between calls, so the component keeps object identity. `#[Locked]` is still enforced
  on `set()`; what cannot surface is tampering with a serialised payload, because there is no
  payload to tamper with.
- **Overlapping drivers share one `redirect` binding.** Two cycles open at once are not something
  Livewire itself ever does; a redirect issued through the `redirect()` helper while both are open
  lands on whichever mounted last. `$this->redirect(...)` from inside the component — the normal
  way — is unaffected, and nesting (`run()` inside `run()`) is fine.
- **No `dehydrate`.** That hook turns a redirect into `abort(redirect(...))` outside a Livewire
  request, so the cycle deliberately stops before it. The consequence: `#[Session]` and `#[Url]`
  properties are not persisted, and the browser effects a response would have carried are never
  emitted — read `redirect()` and `dispatched()` instead, which come from the same place Livewire
  reads them. A method that returns a file response is the same story: `returned()` hands back the
  response object itself, so a caller can stream or store it.
- **`set()` applies one key at a time.** Each write runs its own `updated` hooks before the next
  key is written — the shape `Livewire::test()->set([...])` produces, not the batched one a single
  browser payload produces (there, every value is written first and the hooks run afterwards). It
  matters only when a hook touches another property being set in the same call.
- **Lazy components mount eagerly.** There is no browser to come back for the real component after
  a placeholder, so Livewire's own lazy switch is turned off around the mount and restored straight
  afterwards.
- **`errors()` is per call; `dispatched()` is per cycle.** A call starts with a clean bag, the way a
  request does, while dispatched events accumulate until `finish()`.
- **`get()` uses `data_get()`**, so a path that does not exist and a property that is null both
  come back as `null` — a renamed property in an import loop reads as an empty column.
- **A method that returns a `RedirectResponse`** hands it back through `returned()`; Livewire would
  have turned it into an effect. `$this->redirect(...)` inside the component behaves normally.
- **`finish()` may reset Livewire's per-request state.** When the last open cycle closes in a
  console process — and only when Livewire was not already rendering — `Livewire::flushState()`
  runs, so a long-lived worker starts each unit of work clean.

## Compatibility

Wireless drives Livewire's internal hook pipeline — `trigger('mount')`, `trigger('call')`, the
component and redirector stacks. None of that carries a backwards-compatibility promise, which is
why the Livewire constraint is deliberately narrow (`^4.3`, the version the internals were verified
against) rather than the whole major. CI additionally runs the suite against Livewire's development
branch as a canary, so a moved internal shows up here before it shows up in your application.

## Tested against the real thing

The suite drives real Livewire on both majors — mount parameters and defaults, aliases, lazy
components, container injection, form objects, nested arrays and collections through the
synthesizers, `updated()` hooks, locked and computed properties, dispatched events and listeners,
validation from rules, form objects and `addError()`, redirects (path, named route, `navigate`),
teardown after an exception, nested and concurrent cycles, and a 25-cycle run that must not leak
Livewire state.

```bash
composer install
composer test
```

## Credits

Extracted from a production Laravel app, where an import feature had to run invoice forms — rules,
form objects and all — outside the browser.

## License

MIT. See [LICENSE.md](LICENSE.md).
