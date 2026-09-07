<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use RobertStanciu\Wireless\Exceptions\ComponentAlreadyMountedException;
use RobertStanciu\Wireless\Exceptions\ComponentNotMountedException;
use Throwable;
use WeakMap;

use function Livewire\on;
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
    use Macroable;
    use Tappable;

    /**
     * Every driver with an open cycle. A registry rather than a counter: a driver that is dropped
     * without finishing used to leave the count above zero, and from then on no later cycle would
     * ever restore the shared state — one forgotten finish() poisoned the whole worker. Weak, so
     * being registered here cannot be what keeps an abandoned driver alive.
     *
     * @var WeakMap<self, bool>|null
     */
    private static ?WeakMap $open = null;

    /**
     * The application's redirector as it was registered before the outermost cycle: the container
     * binding, not just the resolved instance. Shared, because two drivers finished in mount order
     * would otherwise put back a Livewire Redirector as if it were Laravel's.
     *
     * @var array{instance: mixed, binding: array<string, mixed>|null}|null
     */
    private static ?array $applicationRedirector = null;

    /** Whether Livewire's own per-request state was already in use when the outermost cycle began. */
    private static bool $livewireWasBusy = false;

    private ?Component $component = null;

    private ?ComponentContext $context = null;

    private mixed $returned = null;

    private bool $throwOnValidationErrors = true;

    private bool $finished = false;

    private bool $pushedComponent = false;

    /** How deep Livewire's redirector stack was before this cycle pushed onto it. */
    private ?int $redirectorDepth = null;

    /** @var array{0: ?string, 1: ?Authenticatable}|null  guard and user to restore at finish() */
    private ?array $previousUser = null;

    private ?Authenticatable $actingAsUser = null;

    /** Readable after finish(), when the component itself is gone. */
    private ?string $finishedRedirect = null;

    /** @var array<int, array{name: string, params: array<array-key, mixed>}> */
    private array $finishedDispatches = [];

    private ?MessageBag $finishedErrors = null;

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
        if ($this->component !== null) {
            throw ComponentAlreadyMountedException::for($this->name);
        }

        // a reused driver must not answer for the cycle before it
        $this->returned = null;
        $this->finished = false;
        $this->finishedRedirect = null;
        $this->finishedDispatches = [];
        $this->finishedErrors = null;

        // resolve first: an unknown component or a failing constructor must not leave a cycle open
        $component = app('livewire')->new($this->name);

        if (self::open()->count() === 0) {
            // SupportRedirects::boot() swaps the container's `redirect` binding for a Livewire
            // Redirector and only puts it back during `dehydrate`, the one hook this cycle must
            // never run. Snapshot how the real one was REGISTERED — restoring only the resolved
            // instance would turn Laravel's singleton into a pinned object holding this request's
            // session for every request after it.
            self::$applicationRedirector = Lifecycle::captureRedirector();

            self::$livewireWasBusy = Lifecycle::livewireIsBusy();
        }

        self::open()[$this] = true;

        $parent = app('livewire')->current();

        $this->component = $component;
        $this->context = new ComponentContext($component, mounting: true);

        $this->redirectorDepth = Lifecycle::redirectorsInFlight();

        // Livewire reads this stack for the parent of anything mounted or rendered inside the
        // cycle; without the push, a nested component would be handed whatever came before.
        Lifecycle::pushComponent($component);
        $this->pushedComponent = true;

        try {
            Lifecycle::mount($component, $params, $parent);
        } catch (Throwable $e) {
            // a component whose mount() throws must not leave the redirector swapped, the stacks
            // unbalanced or the cycle open
            $this->finish();

            throw $e;
        }

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
        // written first, `updated` hooks after — the order a request uses, so a hook may still
        // overwrite what a later property in the same call just set
        Lifecycle::updateProperties(
            $this->component(),
            $this->context(),
            is_array($path) ? $path : [$path => $value],
        );

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

        // a failed call must not leave the previous call's value readable
        $this->returned = null;

        $earlyReturnCalled = false;
        $earlyReturn = null;

        $returnEarly = function ($return = null) use (&$earlyReturnCalled, &$earlyReturn): void {
            $earlyReturnCalled = true;
            $earlyReturn = $return;
        };

        // a call starts with a clean bag, the way a request does — otherwise "are these errors
        // mine?" has no answer: MessageBag de-duplicates, so the same bad input twice would leave
        // the bag unchanged and read as success
        $component->resetErrorBag();

        // Livewire's validation hook swallows the exception and writes the bag instead, so the
        // real one — with its validator, its error bag name and its response — is caught here and
        // rethrown as-is; a bag filled by addError() has no exception to catch and gets a fresh one
        $captured = null;

        // registered inside the try: a hook that throws before it (a lifecycle method called
        // directly, for one) would otherwise leave the listener — and this component — pinned
        $stopCapturing = on('exception', function ($target, $e) use ($component, &$captured): void {
            if ($target === $component && $e instanceof ValidationException) {
                $captured = $e;
            }
        });

        try {
            $finish = Lifecycle::call($component, $method, $params, $context, $returnEarly);

            if ($earlyReturnCalled) {
                $this->returned = $finish($earlyReturn);
            } else {
                $this->guardAgainstUnknownMethod($component, $method);

                // to a variable first: $finish() takes its argument by reference, and a call
                // expression is not a variable — PHP raises "Only variables should be passed by
                // reference" otherwise
                $return = wrap($component)->{$method}(...$params);

                $this->returned = $finish($return);
            }
        } finally {
            $stopCapturing();
        }

        // only once the call itself came back: reporting validation while another exception is
        // unwinding would replace the caller's real failure with a message from the bag
        $this->throwOnValidationFailure($captured);

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

    /** Back to the default: a call that fails validation throws. */
    public function throwValidationErrors(): static
    {
        $this->throwOnValidationErrors = true;

        return $this;
    }

    /**
     * Run the cycle as a given user, putting the previous one back at finish() — a loop that
     * drives one component per row must not leak the last row's user into the next.
     *
     * It has to come BEFORE the mount, because that is where a component reads the current user:
     * `Wireless::actingAs($user)->run(...)` for the closure form, or on the driver before mount().
     *
     * @throws ComponentAlreadyMountedException
     */
    public function actingAs(Authenticatable $user, ?string $guard = null): static
    {
        if ($this->component !== null) {
            throw ComponentAlreadyMountedException::for($this->name);
        }

        $this->previousUser = [$guard, auth()->guard($guard)->user()];
        $this->actingAsUser = $user;

        auth()->guard($guard)->setUser($user);

        return $this;
    }

    public function errors(): MessageBag
    {
        if ($this->component !== null) {
            return $this->component->getErrorBag();
        }

        // never mounted is NOT "no errors": that answer reads as success to a caller inspecting a
        // row that failed before it ever got a component
        $this->guardAgainstReadingBeforeMount();

        return $this->finishedErrors ?? new MessageBag;
    }

    /**
     * Whatever Livewire has already written to the response context. Redirects and dispatches are
     * only added during `dehydrate`, which this cycle never runs, so this is empty unless a custom
     * ComponentHook writes an effect during `mount` or `call` — read redirect() and dispatched().
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function effects(): array
    {
        return $this->context()->effects;
    }

    /**
     * Events the component dispatched to the browser, oldest first, in the shape a response would
     * have carried them: `['name' => 'saved', 'params' => [...]]`.
     *
     * @return array<int, array{name: string, params: array<array-key, mixed>}>
     */
    public function dispatched(): array
    {
        if ($this->component === null) {
            $this->guardAgainstReadingBeforeMount();

            return $this->finishedDispatches;
        }

        return array_map(
            fn (object $event): array => $event->serialize(),
            store($this->component)->get('dispatched', []),
        );
    }

    /**
     * Send an event to the component's own listeners, the way a browser event would arrive. It is
     * a call like any other, so returned() then holds what the LISTENER returned.
     *
     * @throws EventHandlerDoesNotExist
     */
    public function dispatch(string $event, mixed ...$params): static
    {
        return $this->call('__dispatch', $event, $params);
    }

    public function mounted(): bool
    {
        return $this->component !== null;
    }

    /**
     * Where the component redirected, if it did. Read from Livewire's own store rather than the
     * effects: the effect is only recorded during `dehydrate`, which this cycle deliberately never
     * runs — outside a Livewire request that hook turns the redirect into an abort().
     */
    public function redirect(): ?string
    {
        if ($this->component !== null) {
            return store($this->component)->get('redirect');
        }

        $this->guardAgainstReadingBeforeMount();

        return $this->finishedRedirect;
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
            // the mount may have failed after actingAs() switched the user
            $this->rescue(fn () => $this->restorePreviousUser());

            return $this;
        }

        try {
            // what the caller may still want to read once the component is gone. Inside the try:
            // serialising a dispatch to a component Livewire cannot resolve throws, and a
            // convenience read must never be able to abort the teardown below.
            $this->finishedRedirect = $this->rescue(fn () => $this->redirect());
            $this->finishedDispatches = $this->rescue(fn () => $this->dispatched()) ?? [];
            $this->finishedErrors = $this->rescue(fn () => $this->errors());

            Lifecycle::destroy($this->component, $this->context());
        } catch (Throwable $e) {
            // finish() usually runs from a `finally`; a throwing destroy hook must not replace the
            // exception the caller is already handling
            $this->report($e);
        } finally {
            $this->tearDown();
        }

        return $this;
    }

    /** A dropped driver still has to give the shared state back. */
    public function __destruct()
    {
        $this->rescue(fn () => $this->finish());
    }

    private function tearDown(): void
    {
        if ($this->pushedComponent && $this->component !== null) {
            Lifecycle::popComponent($this->component);
        }

        if ($this->redirectorDepth !== null) {
            Lifecycle::unwindRedirectorsTo($this->redirectorDepth);
        }

        $this->component = null;
        $this->context = null;
        $this->finished = true;
        $this->pushedComponent = false;
        $this->redirectorDepth = null;

        $this->rescue(fn () => $this->restorePreviousUser());

        unset(self::open()[$this]);

        if (self::open()->count() > 0) {
            return;
        }

        $this->rescue(fn () => Lifecycle::restoreRedirector(self::$applicationRedirector));

        self::$applicationRedirector = null;

        // flushState() is Livewire's BETWEEN-REQUESTS reset — it drops asset-injection flags,
        // blade keys and every other `flush-state` listener's state. Inside an HTTP request that
        // belongs to the request, so the flush is for console work (jobs, commands), which is
        // where an unflushed process would otherwise carry state between units of work.
        if (! self::$livewireWasBusy && app()->runningInConsole()) {
            Livewire::flushState();
        }

        self::$livewireWasBusy = false;
    }

    /**
     * Livewire has thrown its per-request state away, so everything this class remembers about a
     * cycle — which are open, what the redirector was — describes a world that no longer exists.
     *
     * @internal called from the service provider's `flush-state` listener
     */
    public static function forgetOpenCycles(): void
    {
        self::$open = null;
        self::$applicationRedirector = null;
        self::$livewireWasBusy = false;
    }

    /** @return WeakMap<self, bool> */
    private static function open(): WeakMap
    {
        return self::$open ??= new WeakMap;
    }

    /**
     * Run something whose failure must not derail a teardown.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $work
     * @return TValue|null
     */
    private function rescue(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            $this->report($e);

            return null;
        }
    }

    /** Even the reporter can be misconfigured, and teardown still has to finish. */
    private function report(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // nothing left to do with it
        }
    }

    private function restorePreviousUser(): void
    {
        if ($this->previousUser === null) {
            return;
        }

        [$name, $user] = $this->previousUser;

        $this->previousUser = null;
        $actingAs = $this->actingAsUser;
        $this->actingAsUser = null;

        $guard = auth()->guard($name);

        // another cycle may have switched user after this one did; the last driver to finish must
        // not undo a switch it never made
        if ($actingAs !== null && $guard->user() !== $actingAs) {
            return;
        }

        if ($user !== null) {
            $guard->setUser($user);

            return;
        }

        // "nobody was logged in" is the normal state in a job or a command, and setUser() is typed
        // non-nullable — a session guard can forget its user, anything else is dropped wholesale so
        // the next resolve starts from the request again
        if ($guard instanceof SessionGuard) {
            $guard->forgetUser();

            return;
        }

        // forgetGuards() would drop every OTHER guard the request had authenticated too
        Lifecycle::forgetGuard($name ?? app(AuthManager::class)->getDefaultDriver());
    }

    /** @throws ValidationException */
    private function throwOnValidationFailure(?ValidationException $captured): void
    {
        if (! $this->throwOnValidationErrors) {
            return;
        }

        // the validator's own exception where there was one, so the caller still gets
        // $e->validator->failed(); a bag filled by addError() alone has none to keep
        if ($captured !== null) {
            throw $captured;
        }

        $errors = $this->errors();

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->getMessages());
        }
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

    /** @throws ComponentNotMountedException */
    private function guardAgainstReadingBeforeMount(): void
    {
        if (! $this->finished) {
            throw ComponentNotMountedException::for($this->name);
        }
    }

    private function component(): Component
    {
        return $this->component ?? throw ComponentNotMountedException::for($this->name);
    }

    private function context(): ComponentContext
    {
        return $this->context ?? throw ComponentNotMountedException::for($this->name);
    }
}
