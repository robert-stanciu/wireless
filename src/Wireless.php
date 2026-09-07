<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Component;
use RobertStanciu\Wireless\Exceptions\MissingCallbackException;
use Throwable;

/**
 * Entry point: drive a Livewire component from PHP.
 *
 * A Livewire component is an ordinary class, but the behaviour worth reusing — computed
 * properties, form objects, lifecycle hooks, validation, `wire:model` updates — only happens
 * inside Livewire's own mount/update/call pipeline. Wireless runs that pipeline in-process, so a
 * job, a command or a controller can use a component the way the browser does, without a request.
 */
class Wireless
{
    /** @var array{0: Authenticatable, 1: ?string}|null */
    private ?array $actingAs = null;

    /**
     * Mount a component and hand it to the caller, tearing the cycle down afterwards even when the
     * callback throws. This is the safe default: the fluent driver leaks Livewire state until
     * finish() is called, and a `finally` is easy to forget.
     *
     * The mount parameters may be left out entirely — `run(Counter::class, fn ($c) => …)`.
     *
     * @param  class-string<Component>|string  $component  class name or registered alias
     * @param  array<string, mixed>|Closure(ComponentDriver): TReturn  $params  mount parameters, or the callback
     * @param  Closure(ComponentDriver): TReturn|null  $callback
     * @return TReturn
     *
     * @template TReturn
     *
     * @throws MissingCallbackException|Throwable
     */
    public function run(string $component, array|Closure $params, ?Closure $callback = null): mixed
    {
        if ($params instanceof Closure) {
            [$params, $callback] = [[], $params];
        }

        if ($callback === null) {
            throw MissingCallbackException::make();
        }

        $driver = $this->component($component);

        // the mount belongs INSIDE the try: it swaps the container's redirector and disables lazy
        // loading before it runs the hooks, so a component whose mount() throws would otherwise
        // leave both in place for the rest of the process
        try {
            return $callback($driver->mount($params));
        } finally {
            $driver->finish();
        }
    }

    /**
     * The fluent driver, for callers that want to keep a component around:
     * `Wireless::component(Editor::class)->mount([...])->set('title', 'Hi')->call('save')->finish()`.
     *
     * @param  class-string<Component>|string  $component  class name or registered alias
     */
    public function component(string $component): ComponentDriver
    {
        $driver = new ComponentDriver($component);

        if ($this->actingAs !== null) {
            $driver->actingAs(...$this->actingAs);
        }

        return $driver;
    }

    /**
     * Drive the next cycle as this user — before the mount, which is where a component reads
     * `Auth::user()`. The previous user is put back when the cycle finishes.
     *
     * `Wireless::actingAs($user)->run(CreateInvoice::class, [...], fn ($form) => ...)`
     */
    public function actingAs(Authenticatable $user, ?string $guard = null): self
    {
        $clone = clone $this;

        $clone->actingAs = [$user, $guard];

        return $clone;
    }
}
