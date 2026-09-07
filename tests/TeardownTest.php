<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Livewire\EventBus;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use ReflectionProperty;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Leaky;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;

/** Everything shared: the container binding, Livewire's stacks, and the driver's own counter. */
function sharedState(): array
{
    return [
        'redirect' => app('redirect')::class,
        'components' => count(HandleComponents::$componentStack),
    ];
}

it('leaves nothing behind when the component cannot even be resolved', function () {
    $before = sharedState();

    expect(fn () => Wireless::run('no-such-component', [], fn ($driver) => null))
        ->toThrow(ComponentNotFoundException::class);

    // a cycle counted here would make every later teardown think it was nested
    expect(sharedState())->toBe($before);

    Wireless::run(Traveller::class, [], fn ($traveller) => $traveller->call('toPath'));

    expect(sharedState())->toBe($before);
});

it('restores the redirector even when drivers finish in the order they mounted', function () {
    $before = app('redirect');

    $first = Wireless::component(Counter::class)->mount();
    $second = Wireless::component(Counter::class)->mount();

    $first->finish();   // not the LIFO order a nested cycle would use
    $second->finish();

    expect(app('redirect'))->toBe($before);
});

it('does not resurrect livewire redirector when the container is flushed', function () {
    $before = app('redirect');

    Wireless::run(Traveller::class, [], fn ($traveller) => $traveller->call('toPath'));

    app()->forgetInstance('redirect');

    expect(app('redirect'))->toBeInstanceOf($before::class);
});

it('puts the logged-out state back, not a TypeError', function () {
    expect(auth()->user())->toBeNull();

    Wireless::run(Counter::class, [], fn ($counter) => $counter
        ->actingAs(new User)
        ->tap(fn ($driver) => expect(auth()->check())->toBeTrue()));

    expect(auth()->user())->toBeNull();
});

it('puts the previous user back after acting as another', function () {
    $original = new User;
    auth()->setUser($original);

    Wireless::run(Counter::class, [], fn ($counter) => $counter->actingAs(new User));

    expect(auth()->user())->toBe($original);
});

it('keeps the component exception when the method also left an error behind', function () {
    Wireless::run(Leaky::class, [], function ($leaky) {
        // the bag is dirty, but the reason the call failed is the DomainException
        expect(fn () => $leaky->call('save'))
            ->toThrow(DomainException::class, 'the real failure');
    });
});

it('reports the same validation failure every time it happens', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        $signup->set('email', 'nope');

        foreach (range(1, 3) as $attempt) {
            expect(fn () => $signup->call('save'))->toThrow(ValidationException::class);
        }
    });
});

it('does not accumulate exception listeners when a call throws early', function () {
    $count = function (): int {
        $bus = app(EventBus::class);

        $listeners = (new ReflectionProperty($bus, 'listeners'));

        return count($listeners->getValue($bus)['exception'] ?? []);
    };

    $before = $count();

    Wireless::run(Counter::class, [], function ($counter) {
        foreach (range(1, 50) as $ignored) {
            try {
                $counter->call('mount');   // a lifecycle hook: Livewire itself refuses the call
            } catch (DirectlyCallingLifecycleHooksNotAllowedException) {
                // expected — and it happens before the driver's own try block used to open
            }
        }
    });

    expect($count())->toBe($before);
});
