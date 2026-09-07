<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Closure;
use Livewire\Component;
use Livewire\Mechanisms\HandleComponents\ComponentContext;

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
     * @param  array<string, mixed>  $params
     * @param  Component|false|null  $parent  whatever `app('livewire')->current()` handed back
     * @return Closure(mixed): mixed the "finish" callback Livewire returns for the hook
     */
    public static function mount(Component $component, array $params, mixed $parent): Closure
    {
        return trigger('mount', $component, $params, null, $parent, []);
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
}
