<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportFormObjects\Form;
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

    /** The frame Livewire's redirector stack holds for this cycle, found again by identity. */
    private mixed $redirectorFrame = null;

    /** Teardown is running from the destructor, at a moment nothing else chose. */
    private bool $destructing = false;

    /**
     * Per guard: who was authenticated before the FIRST cycle touched it, how many cycles are
     * acting on it, and the last user one of them set. Shared, because two cycles that finish in
     * mount order would otherwise put back each other's user instead of the application's.
     *
     * @var array<string, array{original: ?Authenticatable, cycles: int, last: ?Authenticatable}>
     */
    private static array $guards = [];

    /** The guard this driver is acting on, resolved to its real name. */
    private ?string $actingAsGuard = null;

    /** A validation failure an `updated` hook produced, waiting for the next call to report it. */
    private ?ValidationException $pendingValidation = null;

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
        $this->pendingValidation = null;
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

        $redirectorsBefore = Lifecycle::redirectorsInFlight();

        // Livewire reads this stack for the parent of anything mounted or rendered inside the
        // cycle; without the push, a nested component would be handed whatever came before.
        Lifecycle::pushComponent($component);
        $this->pushedComponent = true;

        try {
            Lifecycle::mount($component, $params, $parent);
        } catch (Throwable $e) {
            $this->redirectorFrame = Lifecycle::currentRedirectorFrame($redirectorsBefore);

            // a component whose mount() throws must not leave the redirector swapped, the stacks
            // unbalanced or the cycle open
            $this->finish();

            throw $e;
        }

        $this->redirectorFrame = Lifecycle::currentRedirectorFrame($redirectorsBefore);

        return $this;
    }

    /**
     * Update a property the way `wire:model` does — through Livewire, so `updatedX()` hooks,
     * synthesizers and form objects all take part. Nested paths ("form.total") are supported, and
     * several keys are applied one at a time (see Lifecycle::updateProperties()).
     *
     * @param  string|array<string, mixed>  $path
     */
    public function set(string|array $path, mixed $value = null): static
    {
        $component = $this->component();

        // an `updatedX()` hook that calls validateOnly() is the commonest Livewire form idiom, and
        // Livewire swallows its failure into the error bag, exactly as it does for an action. A set
        // does not throw for it — a request would not either, and a caller filling a form field by
        // field should not have to catch after every one — but the failure is remembered, so the
        // next call() reports it instead of wiping the bag and reading as success.
        $failure = $this->captureValidationFailure(
            $component,
            fn () => Lifecycle::updateProperties(
                $component,
                $this->context(),
                is_array($path) ? $path : [$path => $value],
            ),
        );

        $this->pendingValidation = $failure ?? $this->stillFailing($this->pendingValidation);

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

        // Livewire carries the error bag between requests (SupportValidation dehydrates it into
        // the memo and hydrates it back), so it is NOT reset here — a caller reading errors() after
        // two calls sees what a browser would. "Did THIS call fail?" is answered by the captured
        // exception, and by the bag having changed.
        $before = $this->errors()->getMessages();

        $pending = $this->pendingValidation;
        $this->pendingValidation = null;

        $captured = $this->captureValidationFailure($component, function () use ($component, $method, $params, $context): void {
            $earlyReturnCalled = false;
            $earlyReturn = null;

            $returnEarly = function ($return = null) use (&$earlyReturnCalled, &$earlyReturn): void {
                $earlyReturnCalled = true;
                $earlyReturn = $return;
            };

            $finish = Lifecycle::call($component, $method, $params, $context, $returnEarly);

            if ($earlyReturnCalled) {
                $this->returned = $finish($earlyReturn);

                return;
            }

            $this->guardAgainstUnknownMethod($component, $method);

            // to a variable first: $finish() takes its argument by reference, and a call
            // expression is not a variable — PHP raises "Only variables should be passed by
            // reference" otherwise
            $return = wrap($component)->{$method}(...$params);

            $this->returned = $finish($return);
        });

        // only once the call itself came back: reporting validation while another exception is
        // unwinding would replace the caller's real failure with a message from the bag. A failure
        // an earlier set() collected counts as this call's, unless the call produced its own.
        $this->throwOnValidationFailure($captured ?? $pending, $before);

        return $this;
    }

    /** What the last call() returned. */
    public function returned(): mixed
    {
        if ($this->component === null) {
            // null is what a method returning nothing gives back — a caller inspecting a row that
            // never got a component must not read that as an answer
            $this->guardAgainstReadingBeforeMount();
        }

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

        $name = $guard ?? app(AuthManager::class)->getDefaultDriver();

        // a second actingAs() on the same driver switches user again; it does not open a second
        // cycle, and it must not turn the first acting user into "the previous one"
        if ($this->actingAsGuard === null) {
            $this->actingAsGuard = $name;

            self::$guards[$name] ??= [
                'original' => auth()->guard($name)->user(),
                'cycles' => 0,
                'last' => null,
            ];

            self::$guards[$name]['cycles']++;
        }

        self::$guards[$this->actingAsGuard]['last'] = $user;

        auth()->guard($this->actingAsGuard)->setUser($user);

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
        $this->destructing = true;

        $this->rescue(fn () => $this->finish());
    }

    private function tearDown(): void
    {
        if ($this->pushedComponent && $this->component !== null) {
            Lifecycle::popComponent($this->component);
        }

        $this->rescue(fn () => Lifecycle::unwindRedirector($this->redirectorFrame));

        $this->component = null;
        $this->context = null;
        $this->finished = true;
        $this->pushedComponent = false;
        $this->redirectorFrame = null;

        $this->rescue(fn () => $this->restorePreviousUser());

        unset(self::open()[$this]);

        if (self::open()->count() > 0) {
            return;
        }

        // only once nobody else owns a frame: a Livewire component rendering into the surrounding
        // request still has its own redirector bound, and handing the application's back would
        // send that component's redirect nowhere
        if (Lifecycle::redirectorsInFlight() === 0) {
            $this->rescue(fn () => Lifecycle::restoreRedirector(self::$applicationRedirector));
        }

        self::$applicationRedirector = null;

        // flushState() is Livewire's BETWEEN-REQUESTS reset — it drops asset-injection flags,
        // blade keys and every other `flush-state` listener's state. Inside an HTTP request that
        // belongs to the request, so the flush is for console work (jobs, commands), which is
        // where an unflushed process would otherwise carry state between units of work.
        //
        // Asked again HERE, not just at mount: a destructor runs at a moment nothing chose — it can
        // land in the middle of somebody else's render, where flushing would pull that request's
        // state out from under it.
        if (! self::$livewireWasBusy
            && ! $this->destructing
            && ! Lifecycle::livewireIsBusy()
            && app()->runningInConsole()) {
            // one throwing `flush-state` listener must not replace the caller's own exception
            $this->rescue(fn () => Livewire::flushState());
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
        // the bookkeeping for those cycles is about to disappear, so nothing would ever put the
        // container's redirector back — hand it over now, while what it was is still known
        if (self::$open !== null && self::$open->count() > 0) {
            try {
                Lifecycle::restoreRedirector(self::$applicationRedirector);
            } catch (Throwable) {
                // a teardown never gets to fail
            }
        }

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
        if ($this->actingAsGuard === null) {
            return;
        }

        $name = $this->actingAsGuard;
        $this->actingAsGuard = null;

        if (! isset(self::$guards[$name])) {
            return;
        }

        self::$guards[$name]['cycles']--;

        // another cycle is still acting on this guard: the application's user goes back when the
        // last of them finishes, in whatever order they do
        if (self::$guards[$name]['cycles'] > 0) {
            return;
        }

        ['original' => $user, 'last' => $actingAs] = self::$guards[$name];

        unset(self::$guards[$name]);

        $guard = auth()->guard($name);

        // the component may have logged somebody in itself; a teardown must not undo that
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
        Lifecycle::forgetGuard($name);
    }

    /**
     * Livewire's validation hook swallows the exception and writes the error bag instead, so the
     * real one — with its validator, its bag name and its response — is caught here and kept.
     *
     * @param  Closure(): void  $work
     *
     * @throws Throwable whatever $work threw
     */
    private function captureValidationFailure(Component $component, Closure $work): ?ValidationException
    {
        $captured = null;

        $stopCapturing = on('exception', function ($target, $e) use ($component, &$captured): void {
            // a form object's own hooks report themselves as the target; Livewire's registry maps
            // them back the same way, and validateOnly() inside a form is where most apps put it
            if ($target instanceof Form) {
                $target = $target->getComponent();
            }

            if ($target === $component && $e instanceof ValidationException) {
                $captured = $e;
            }
        });

        try {
            $work();
        } finally {
            $stopCapturing();
        }

        return $captured;
    }

    /** A failure only stands while its fields are still in the bag: a corrected value clears it. */
    private function stillFailing(?ValidationException $failure): ?ValidationException
    {
        if ($failure === null) {
            return null;
        }

        return $this->errors()->hasAny(array_keys($failure->errors())) ? $failure : null;
    }

    /**
     * @param  array<string, array<int, string>>  $before  the bag as it stood before the call
     *
     * @throws ValidationException
     */
    private function throwOnValidationFailure(?ValidationException $captured, array $before = []): void
    {
        if (! $this->throwOnValidationErrors) {
            // the bag was reset for this call, so a failure carried over from an earlier set() has
            // to be put back — the caller turned throwing off in order to READ these
            if ($captured !== null) {
                $this->component()->setErrorBag(
                    $this->errors()->merge($captured->errors())
                );
            }

            return;
        }

        if ($captured !== null) {
            throw $captured;
        }

        // a bag filled by addError() alone has no exception to keep, so one is built from what
        // THIS call put there — errors it inherited are not its failure to report
        $after = $this->errors()->getMessages();

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

        // '__dispatch' is not listed: SupportEvents answers it inside the `call` hook and returns
        // early, so it never reaches this guard — Livewire's own copy of the check is for a path
        // this cycle does not take.
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
