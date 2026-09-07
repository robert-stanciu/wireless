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

composer require robert-stanciu/wireless:^2.0   # Livewire 4
composer require robert-stanciu/wireless:^1.0   # Livewire 3
```

| Wireless | Livewire | Laravel | PHP |
| --- | --- | --- | --- |
| `^2.0` | `^4.0` | 12, 13 | 8.2+ |
| `^1.0` | `^3.0` | 12, 13 | 8.2+ |

You are reading the `2.x` line (Livewire 4). The two majors exist because Livewire changed the
signatures of the `mount` and `call` hooks; the package API is identical on both, and features ship
to both lines at once.

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

$driver->dispatch('order-placed', reference: 'INV-1');  // deliver an event to its own listeners
$driver->actingAs($user);                        // for this cycle; the previous user is put back
$driver->tap(fn ($d) => logger($d->get('total')));
$driver->errors();                               // the component's MessageBag
$driver->redirect();                             // '/dashboard', or null
$driver->dispatches();                           // [['name' => 'saved', 'params' => [...]], ...]
$driver->effects();                              // whatever Livewire has already written to the context
$driver->instance();                             // the component itself

$driver->finish();                               // destroy hooks + flush state (idempotent)
```

Outside `run()`, **`finish()` is yours to call** — until then Livewire's per-request state stays
loaded and the container keeps Livewire's redirector.

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
  serialised between calls, so the component keeps object identity — checksum and `#[Locked]`
  violations that only a round trip could surface will not appear.
- **No `dehydrate`.** That hook turns a redirect into `abort(redirect(...))` outside a Livewire
  request, so the cycle deliberately stops before it. The consequence: `#[Session]` and `#[Url]`
  properties are not persisted, and `$this->download()` produces no effect — read `redirect()` and
  `dispatches()` instead, which come from the same place Livewire reads them.
- **Values are assigned, not hydrated from the wire.** `set('date', '2025-01-01')` on a
  `public Carbon $date` assigns the string; a browser update would run it through the synthesizers
  first. Pass real PHP values (`set('date', now())`).
- **Lazy components mount eagerly**, per cycle — the mount is told `lazy: false` the way
  `<livewire:x :lazy="false">` does, so Livewire's global switch is left alone. There is no browser
  to come back for the real component after a placeholder.

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
