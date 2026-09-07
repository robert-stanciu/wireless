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

```bash
composer require robert-stanciu/wireless
```

| Wireless | Livewire |
| --- | --- |
| `^2.0` | `^4.0` |
| `^1.0` | `^3.0` |

The two majors exist because Livewire changed the signatures of the `mount` and `call` hooks; the
package API is identical on both.

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

$driver->errors();                               // the component's MessageBag
$driver->redirect();                             // '/dashboard', or null
$driver->effects();                              // dispatches, and everything else Livewire recorded
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

Prefer to inspect the bag instead? `->keepValidationErrors()` turns the throw off for that driver.

## What it does not do

- **No rendering.** Wireless drives behaviour, not Blade. Reach for `Livewire::test()` (assertions
  against HTML) or a real request when you need the view.
- **No client round trip.** There is no snapshot, no checksum, no `wire:navigate`; effects are
  readable but nothing is sent anywhere.
- **Lazy components mount eagerly.** There is no browser to ask for the real component after the
  placeholder, so `Livewire::withoutLazyLoading()` is applied to the cycle.

## Testing

```bash
composer install
composer test
```

## Credits

Extracted from a production Laravel app, where an import feature had to run invoice forms — rules,
form objects and all — outside the browser.

## License

MIT. See [LICENSE.md](LICENSE.md).
