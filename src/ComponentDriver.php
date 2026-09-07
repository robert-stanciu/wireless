<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use RobertStanciu\Wireless\Exceptions\ComponentNotMounted;

use function Livewire\store;
use function Livewire\wrap;

/**
 * One component, driven through Livewire's own pipeline: mount hooks, property updates and method
 * calls all go through the same code the browser reaches, so form objects, computed properties,
 * `updatedX()` hooks and validation behave exactly as they do in a request.
 *
 * Anything mounted must be finished — `finish()` fires the destroy hooks, flushes Livewire's state
 * and puts back the container bindings Livewire swapped. `Wireless::run()` does that for you.
 */
class ComponentDriver
{
    private ?Component $component = null;

    private ?ComponentContext $context = null;

    private mixed $returned = null;

    private bool $throwOnValidationErrors = true;

    /** The application's real redirector, put back by finish(). */
    private mixed $originalRedirector = null;

    /** @param  class-string<Component>|string  $name  class name or registered alias */
    public function __construct(private readonly string $name) {}

    /**
     * Run the mount cycle. Lazy loading is disabled for it: a lazy component would otherwise mount
     * its placeholder instead of itself, and there is no browser here to ask for the real thing.
     *
     * @param  array<string, mixed>  $params
     */
    public function mount(array $params = []): static
    {
        Livewire::withoutLazyLoading();

        $parent = app('livewire')->current();

        $this->component = app('livewire')->new($this->name);
        $this->context = new ComponentContext($this->component, mounting: true);

        // SupportRedirects::boot() swaps the container's `redirect` binding for a Livewire
        // Redirector and only restores it during the `dehydrate` hook, which this cycle never
        // reaches. Snapshot the real one so the surrounding request keeps emitting genuine
        // RedirectResponse objects once we are done.
        $this->originalRedirector = app('redirect');

        Lifecycle::mount($this->component, $params, $parent);

        return $this;
    }

    /**
     * Update a property the way `wire:model` does — through Livewire, so `updatedX()` hooks,
     * synthesizers and form objects all take part. Nested paths ("form.total") are supported.
     *
     * @param  string|array<string, mixed>  $path
     */
    public function set(string|array $path, mixed $value = null): static
    {
        foreach (is_array($path) ? $path : [$path => $value] as $key => $newValue) {
            Livewire::updateProperty($this->component(), $key, $newValue);
        }

        return $this;
    }

    /** Read a property, dotted paths included. */
    public function get(string $path): mixed
    {
        return data_get($this->component(), $path);
    }

    /**
     * Call a public method, hooks and all. The return value is kept for returned(), so calls stay
     * chainable; validation failures surface as a ValidationException unless you opted out.
     *
     * @throws MethodNotFoundException|ValidationException
     */
    public function call(string $method, mixed ...$params): static
    {
        $component = $this->component();
        $context = $this->context();

        $earlyReturnCalled = false;
        $earlyReturn = null;

        $returnEarly = function ($return = null) use (&$earlyReturnCalled, &$earlyReturn): void {
            $earlyReturnCalled = true;
            $earlyReturn = $return;
        };

        $finish = Lifecycle::call($component, $method, $params, $context, $returnEarly);

        if ($earlyReturnCalled) {
            $this->returned = $finish($earlyReturn);

            return $this;
        }

        $this->guardAgainstUnknownMethod($component, $method);

        // to a variable first: $finish() takes its argument by reference, and a call expression is
        // not a variable — PHP raises "Only variables should be passed by reference" otherwise
        $return = wrap($component)->{$method}(...$params);

        $this->returned = $finish($return);

        if ($this->throwOnValidationErrors && $this->errors()->isNotEmpty()) {
            throw ValidationException::withMessages($this->errors()->getMessages());
        }

        return $this;
    }

    /** What the last call() returned. */
    public function returned(): mixed
    {
        return $this->returned;
    }

    /** Keep failed validation in the error bag instead of throwing out of call(). */
    public function keepValidationErrors(): static
    {
        $this->throwOnValidationErrors = false;

        return $this;
    }

    public function errors(): MessageBag
    {
        return $this->component()->getErrorBag();
    }

    /**
     * Effects Livewire recorded for the browser (`redirect`, `dispatches`, …) — the same payload a
     * real request would have returned.
     *
     * @return array<string, mixed>
     */
    public function effects(): array
    {
        return $this->context()->effects;
    }

    /**
     * Where the component redirected, if it did. Read from Livewire's own store rather than the
     * effects: the effect is only recorded during `dehydrate`, which this cycle deliberately never
     * runs — outside a Livewire request that hook turns the redirect into an abort().
     */
    public function redirect(): ?string
    {
        return store($this->component())->get('redirect');
    }

    /** The component itself, for assertions or for reading anything this driver does not expose. */
    public function instance(): Component
    {
        return $this->component();
    }

    /**
     * End the cycle: destroy hooks, Livewire's per-request state, and the container bindings it
     * swapped. Safe to call twice, so a `finally` never needs a guard.
     */
    public function finish(): static
    {
        if ($this->component === null) {
            return $this;
        }

        try {
            Lifecycle::destroy($this->component, $this->context());

            Livewire::flushState();
        } finally {
            app()->instance('redirect', $this->originalRedirector);

            $this->component = null;
            $this->context = null;
        }

        return $this;
    }

    /** @throws MethodNotFoundException */
    private function guardAgainstUnknownMethod(Component $component, string $method): void
    {
        $methods = array_values(array_diff(
            Utils::getPublicMethodsDefinedBySubClass($component),
            ['render'],
        ));

        $methods[] = '__dispatch';

        if (! in_array($method, $methods, true)) {
            throw new MethodNotFoundException($method);
        }
    }

    private function component(): Component
    {
        return $this->component ?? throw ComponentNotMounted::for($this->name);
    }

    private function context(): ComponentContext
    {
        $this->component();

        return $this->context;
    }
}
