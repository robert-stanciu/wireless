<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use RobertStanciu\Wireless\Exceptions\AlreadyMounted;
use RobertStanciu\Wireless\Exceptions\ComponentNotMounted;
use Throwable;

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

    /** Cycles alive across all drivers, so nested ones only tear the shared state down once. */
    private static int $liveCycles = 0;

    /**
     * The application's redirector, captured when the outermost cycle opened. Shared rather than
     * per-driver: two drivers finished in mount order would otherwise put back a Livewire
     * Redirector as if it were Laravel's.
     */
    private static mixed $applicationRedirector = null;

    /** Whether Livewire's own per-request state was already in use when the outermost cycle began. */
    private static bool $livewireWasBusy = false;

    private ?Component $component = null;

    private ?ComponentContext $context = null;

    private mixed $returned = null;

    private bool $throwOnValidationErrors = true;

    private bool $pushedComponent = false;

    private bool $pushedRedirector = false;

    /** @var array{0: ?string, 1: ?Authenticatable}|null  guard and user to restore at finish() */
    private ?array $previousUser = null;

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
            throw AlreadyMounted::for($this->name);
        }

        // resolve first: an unknown component or a failing constructor must not leave a cycle open
        $component = app('livewire')->new($this->name);

        if (self::$liveCycles === 0) {
            // SupportRedirects::boot() swaps the container's `redirect` binding for a Livewire
            // Redirector and only puts it back during `dehydrate`, the one hook this cycle must
            // never run. Snapshot the real one so the surrounding request keeps emitting genuine
            // RedirectResponse objects once we are done.
            self::$applicationRedirector = app('redirect');

            self::$livewireWasBusy = Lifecycle::componentsInFlight() > 0
                || Lifecycle::aComponentHasRendered()
                || Livewire::isLivewireRequest();
        }

        self::$liveCycles++;

        $parent = app('livewire')->current();

        $this->component = $component;
        $this->context = new ComponentContext($component, mounting: true);

        $redirectorsBefore = Lifecycle::redirectorsInFlight();

        // Livewire reads this stack for the parent of anything mounted or rendered inside the
        // cycle; without the push, a nested component would be handed whatever came before.
        Lifecycle::pushComponent($component);
        $this->pushedComponent = true;

        try {
            Lifecycle::mount($component, $params, $parent);
        } catch (Throwable $e) {
            // a component whose mount() throws must not leave the redirector swapped, the stacks
            // unbalanced or the cycle counted
            $this->pushedRedirector = Lifecycle::redirectorsInFlight() > $redirectorsBefore;

            $this->finish();

            throw $e;
        }

        $this->pushedRedirector = Lifecycle::redirectorsInFlight() > $redirectorsBefore;

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

        // the bag survives between calls, exactly as it does inside one request, so only the errors
        // THIS call is responsible for may turn into an exception
        $before = $this->errors()->getMessages();

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
        $this->throwOnNewValidationErrors($before, $captured);

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
     */
    public function actingAs(Authenticatable $user, ?string $guard = null): static
    {
        $this->previousUser = [$guard, auth()->guard($guard)->user()];

        auth()->guard($guard)->setUser($user);

        return $this;
    }

    public function errors(): MessageBag
    {
        return $this->component === null
            ? $this->finishedErrors ?? new MessageBag
            : $this->component->getErrorBag();
    }

    /**
     * Whatever Livewire has already written to the response context. Most effects — redirects,
     * dispatches — are only added during `dehydrate`, which this cycle never runs, so prefer
     * redirect() and dispatches(); this is here for hooks that record theirs earlier.
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
    public function dispatches(): array
    {
        if ($this->component === null) {
            return $this->finishedDispatches;
        }

        return array_map(
            fn (object $event): array => $event->serialize(),
            store($this->component)->get('dispatched', []),
        );
    }

    /**
     * Send an event to the component's own listeners, the way a browser event would arrive.
     *
     * @throws EventHandlerDoesNotExist
     */
    public function dispatch(string $event, mixed ...$params): static
    {
        return $this->call('__dispatch', $event, $params);
    }

    /** Step out of the chain for a side effect, then carry on. */
    public function tap(Closure $callback): static
    {
        $callback($this);

        return $this;
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
        return $this->component === null
            ? $this->finishedRedirect
            : store($this->component)->get('redirect');
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

        // what the caller may still want to read once the component is gone
        $this->finishedRedirect = $this->redirect();
        $this->finishedDispatches = $this->dispatches();
        $this->finishedErrors = $this->errors();

        try {
            Lifecycle::destroy($this->component, $this->context());
        } catch (Throwable $e) {
            // finish() usually runs from a `finally`; a throwing destroy hook must not replace the
            // exception the caller is already handling
            report($e);
        } finally {
            $this->tearDown();
        }

        return $this;
    }

    private function tearDown(): void
    {
        if ($this->pushedComponent && $this->component !== null) {
            Lifecycle::popComponent($this->component);
        }

        if ($this->pushedRedirector) {
            Lifecycle::popRedirectorStack();
        }

        $this->component = null;
        $this->context = null;
        $this->pushedComponent = false;
        $this->pushedRedirector = false;

        // its own try: a guard that refuses the restore must not skip the teardown below
        try {
            $this->restorePreviousUser();
        } catch (Throwable $e) {
            report($e);
        }

        self::$liveCycles = max(0, self::$liveCycles - 1);

        if (self::$liveCycles > 0) {
            return;
        }

        Lifecycle::restoreRedirector(self::$applicationRedirector);

        self::$applicationRedirector = null;

        // flushState() is Livewire's BETWEEN-REQUESTS reset: run while a real request is still
        // rendering components it would drop their asset injection, blade keys and redirector
        // stack, so it only runs when Wireless was the only thing driving components.
        if (! self::$livewireWasBusy) {
            Livewire::flushState();
        }

        self::$livewireWasBusy = false;
    }

    private function restorePreviousUser(): void
    {
        if ($this->previousUser === null) {
            return;
        }

        [$guard, $user] = $this->previousUser;

        $this->previousUser = null;

        // setUser() is typed non-nullable, and "nobody was logged in" is the normal state in a job
        $user === null
            ? auth()->guard($guard)->forgetUser()
            : auth()->guard($guard)->setUser($user);
    }

    /**
     * @param  array<string, array<int, string>>  $before  the bag as it stood before the call
     *
     * @throws ValidationException
     */
    private function throwOnNewValidationErrors(array $before, ?ValidationException $captured): void
    {
        if (! $this->throwOnValidationErrors) {
            return;
        }

        $after = $this->errors()->getMessages();

        // a captured exception is proof THIS call failed validation — the same bad input twice
        // running produces an identical bag, which a diff alone would read as success
        if ($captured !== null) {
            throw $captured;
        }

        if ($after !== [] && $after !== $before) {
            throw ValidationException::withMessages($after);
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

    private function component(): Component
    {
        return $this->component ?? throw ComponentNotMounted::for($this->name);
    }

    private function context(): ComponentContext
    {
        return $this->context ?? throw ComponentNotMounted::for($this->name);
    }
}
