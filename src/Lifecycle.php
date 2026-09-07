<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Livewire\Component;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

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
     * `lazy`/`defer` are reserved mount params: passing them false is how Blade writes
     * `<livewire:x :lazy="false">`, and it keeps this cycle from mounting a placeholder without
     * touching Livewire's global lazy-loading switch.
     *
     * @param  array<string, mixed>  $params
     * @param  Component|false|null  $parent  whatever `app('livewire')->current()` handed back
     * @return Closure(mixed): mixed the "finish" callback Livewire returns for the hook
     */
    public static function mount(Component $component, array $params, mixed $parent): Closure
    {
        return trigger('mount', $component, ['lazy' => false, 'defer' => false, ...$params], null, $parent, []);
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

    /** Only ever pops what this cycle pushed — a hook that threw mid-mount may have left nothing. */
    public static function popComponent(Component $component): void
    {
        if (end(HandleComponents::$componentStack) === $component) {
            array_pop(HandleComponents::$componentStack);
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

        foreach ($values as $path => $value) {
            $finish = $handler->updateProperty($component, $path, $value, $context);

            $finish();
        }
    }

    /**
     * SupportRedirects::boot() pushes the application's redirector onto a static stack and only
     * pops it in `dehydrate` — the one hook this cycle must never run. Without this the stack grows
     * for every component a long-running worker drives.
     */
    public static function popRedirectorStack(): void
    {
        array_pop(SupportRedirects::$redirectorCacheStack);
    }

    /**
     * Put the application's redirector back. `instance()` alone would leave Livewire's `bind()`
     * shadowed but present, so a container flush (Octane, a test) would resurrect a Redirector
     * pointing at a component that no longer exists.
     */
    public static function restoreRedirector(mixed $redirector): void
    {
        app()->bind('redirect', fn () => $redirector);

        app()->instance('redirect', $redirector);
    }
}
