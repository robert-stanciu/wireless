<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Illuminate\Auth\AuthManager;
use Livewire\Component;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Livewire\Mechanisms\HandleSynths\HandleSynths;
use ReflectionProperty;

use function Livewire\trigger;

/**
 * The two Livewire hooks whose signatures move between major versions, kept in one class so a
 * release for another Livewire major differs by this file alone.
 *
 * This is the Livewire 4 shape: `mount` takes the html attributes and `call` takes the client
 * metadata and the call index. Both are passed POSITIONALLY to every registered hook, so a missing
 * argument is an ArgumentCountError rather than a quietly skipped hook.
 *
 * @internal
 */
final class Lifecycle
{
    public const LIVEWIRE_MAJOR = 4;

    /**
     * There is no browser to come back for a lazy component, so the placeholder is skipped —
     * through Livewire's own switch, restored immediately afterwards. Injecting `lazy: false` into
     * the params instead would reach the component: Livewire writes matching public properties from
     * that array and forwards the rest to `mount()`, so a `$defer` property, a `mount($lazy = …)`
     * default or a variadic `mount(...$args)` would all be corrupted by the flags.
     *
     * @param  array<string, mixed>  $params
     * @param  Component|false|null  $parent  whatever `app('livewire')->current()` handed back
     * @return Closure(mixed): mixed the "finish" callback Livewire returns for the hook
     */
    public static function mount(Component $component, array $params, mixed $parent): Closure
    {
        $lazyWasDisabled = SupportLazyLoading::$disableWhileTesting;

        SupportLazyLoading::$disableWhileTesting = true;

        try {
            return trigger('mount', $component, $params, null, $parent, []);
        } finally {
            SupportLazyLoading::$disableWhileTesting = $lazyWasDisabled;
        }
    }

    /**
     * @param  array<int, mixed>  $params
     * @param  Closure(mixed=): void  $returnEarly
     * @return Closure(mixed): mixed
     */
    public static function call(
        Component $component,
        string $method,
        array $params,
        ComponentContext $context,
        Closure $returnEarly,
    ): Closure {
        return trigger('call', $component, $method, $params, $context, $returnEarly, [], 0);
    }

    public static function destroy(Component $component, ComponentContext $context): void
    {
        trigger('destroy', $component, $context);
    }

    /**
     * Livewire brackets a real cycle with this stack, and reads it back as the PARENT of anything
     * mounted or rendered inside — nested components, #[Reactive] props, parent listeners.
     */
    public static function pushComponent(Component $component): void
    {
        HandleComponents::$componentStack[] = $component;
    }

    /**
     * Remove this cycle's component wherever it sits — drivers finished out of order would
     * otherwise strand it, and Livewire hands whatever is on top to the next mount as its parent.
     */
    public static function popComponent(Component $component): void
    {
        $index = array_search($component, HandleComponents::$componentStack, true);

        if ($index !== false) {
            array_splice(HandleComponents::$componentStack, $index, 1);
        }
    }

    public static function componentsInFlight(): int
    {
        return count(HandleComponents::$componentStack);
    }

    public static function redirectorsInFlight(): int
    {
        return count(SupportRedirects::$redirectorCacheStack);
    }

    /**
     * Livewire has already rendered a component into this response, so its per-request state (asset
     * injection, blade keys, the redirector stack) is still in use and must not be flushed.
     */
    public static function aComponentHasRendered(): bool
    {
        return SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest;
    }

    /**
     * One update at a time — write, then run that property's `updated` hooks before the next one,
     * which is what `Livewire::test()->set([...])` does (it sends one request per key) and what a
     * person typing into a form produces. Batching all the writes first is the shape a single
     * browser payload has, and it gives a different answer whenever a hook touches another
     * property, so the testable semantics win.
     *
     * Livewire's own `updateProperty` is used rather than the manager's, so the update runs in
     * THIS cycle's context and its effects land where the driver can read them.
     *
     * @param  array<string, mixed>  $values
     */
    public static function updateProperties(Component $component, ComponentContext $context, array $values): void
    {
        $handler = app(HandleComponents::class);
        $synths = app(HandleSynths::class);

        foreach ($values as $path => $value) {
            // hydrateForUpdate is what turns '2025-01-01' into a Carbon and 'live' into a backed
            // enum; without it a typed property assignment aborts with a bare 419
            $hydrated = $synths->hydrateForUpdate([], $path, $value, $context);

            $finish = $handler->updateProperty($component, $path, $hydrated, $context);

            $finish();
        }
    }

    /**
     * The frame SupportRedirects::boot() pushed for this cycle — the redirector ITSELF, not its
     * position: releasing a frame renumbers everything above it, so an index recorded at mount
     * points at somebody else's frame by the time this cycle ends.
     */
    public static function currentRedirectorFrame(int $depthBefore): mixed
    {
        return SupportRedirects::$redirectorCacheStack[$depthBefore] ?? null;
    }

    /**
     * Remove one cycle's frame, putting the redirector back as Livewire's `dehydrate` would — but
     * only when the frame is the top one. Rebinding from underneath would tear down a cycle that is
     * still open whenever drivers finish out of order (or a destructor fires late), and its
     * component would then redirect into a container that no longer points at it.
     */
    public static function unwindRedirector(mixed $frame): void
    {
        if ($frame === null) {
            return;
        }

        $index = array_search($frame, SupportRedirects::$redirectorCacheStack, true);

        if ($index === false) {
            return;
        }

        if ($index === array_key_last(SupportRedirects::$redirectorCacheStack)) {
            array_pop(SupportRedirects::$redirectorCacheStack);

            app()->instance('redirect', $frame);

            return;
        }

        array_splice(SupportRedirects::$redirectorCacheStack, $index, 1);
    }

    /**
     * How `redirect` was registered before Livewire swapped it: the binding as well as the resolved
     * instance. Restoring only the instance would leave Livewire's own `bind()` in place, ready to
     * resurrect a Redirector pointing at a finished component the next time the container is
     * flushed; restoring a closure over the instance would demote Laravel's singleton to a frozen
     * object holding one request's session.
     *
     * @return array{instance: mixed, binding: array{concrete: mixed, shared: bool}|null}
     */
    public static function captureRedirector(): array
    {
        return [
            'instance' => app('redirect'),
            'binding' => app()->getBindings()['redirect'] ?? null,
        ];
    }

    /** @param  array{instance: mixed, binding: array{concrete: mixed, shared: bool}|null}|null  $redirector */
    public static function restoreRedirector(?array $redirector): void
    {
        if ($redirector === null) {
            return;
        }

        if ($redirector['binding'] !== null) {
            app()->bind('redirect', $redirector['binding']['concrete'], $redirector['binding']['shared']);
        }

        app()->instance('redirect', $redirector['instance']);
    }

    /** Is Livewire's own per-request state in use — a render in flight, or an update request? */
    public static function livewireIsBusy(): bool
    {
        return self::componentsInFlight() > 0
            || self::aComponentHasRendered()
            || app(FrontendAssets::class)->hasRenderedScripts
            || app(FrontendAssets::class)->hasRenderedStyles
            || Livewire::isLivewireRequest();
    }

    /** Drops one guard's resolved instance; forgetGuards() would drop every other guard too. */
    public static function forgetGuard(string $name): void
    {
        $manager = app(AuthManager::class);

        $guards = new ReflectionProperty($manager, 'guards');

        $resolved = $guards->getValue($manager);

        unset($resolved[$name]);

        $guards->setValue($manager, $resolved);
    }
}
