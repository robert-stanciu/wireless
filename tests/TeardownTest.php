<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Livewire\EventBus;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Features\SupportRedirects\Redirector;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use ReflectionProperty;
use RobertStanciu\Wireless\Exceptions\ComponentAlreadyMountedException;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Exploding;
use RobertStanciu\Wireless\Tests\Fixtures\Leaky;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;
use RobertStanciu\Wireless\Tests\Fixtures\WhoAmI;

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

    // Livewire's Redirector EXTENDS Laravel's, so the class check has to be the negative one
    expect(app('redirect'))->not->toBeInstanceOf(Redirector::class)
        ->and(app()->isShared('redirect'))->toBeTrue();
});

it('puts the logged-out state back, not a TypeError', function () {
    expect(auth()->user())->toBeNull();

    Wireless::actingAs(new User)->run(Counter::class, [], fn ($counter) => expect(auth()->check())->toBeTrue());

    expect(auth()->user())->toBeNull();
});

it('puts the previous user back after acting as another', function () {
    $original = new User;
    auth()->setUser($original);

    Wireless::actingAs(new User)->run(Counter::class, [], fn ($counter) => expect(auth()->user())->not->toBe($original));

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

it('acts as the user for the mount itself, not just the calls after it', function () {
    $user = new User;
    $user->id = 42;

    // the component reads Auth::user() in mount(), which run() performs before the callback ever
    // sees the driver — so the user has to be set on the manager, not on the driver
    $seen = Wireless::actingAs($user)->run(WhoAmI::class, [], fn ($driver) => $driver->get('mountedAs'));

    expect($seen)->toBe($user->getAuthIdentifier())
        ->and(auth()->user())->toBeNull();
});

it('refuses to switch user once the component is mounted', function () {
    $driver = Wireless::component(Counter::class)->mount();

    expect(fn () => $driver->actingAs(new User))
        ->toThrow(ComponentAlreadyMountedException::class);

    $driver->finish();
});

it('does not hand a reused driver the previous cycle outcome', function () {
    $driver = Wireless::component(Traveller::class);

    $driver->mount()->call('toPath')->finish();

    expect($driver->redirect())->toBe('/somewhere');

    $driver->mount();

    expect($driver->redirect())->toBeNull()
        ->and($driver->errors()->isEmpty())->toBeTrue()
        ->and($driver->returned())->toBeNull();

    $driver->finish();
});

it('restores everything when a fluent mount throws', function () {
    $before = app('redirect');

    try {
        Wireless::component(Exploding::class)->mount();
    } catch (DomainException) {
        // expected
    }

    expect(app('redirect'))->toBe($before)
        ->and(HandleComponents::$componentStack)->toBe([])
        ->and(SupportRedirects::$redirectorCacheStack)->toBe([]);
});

it('acts as a user on a named guard, and leaves the other guards alone', function () {
    config()->set('auth.guards.second', ['driver' => 'session', 'provider' => 'users']);

    $onDefault = new User;
    $onDefault->id = 1;
    auth()->setUser($onDefault);

    Wireless::actingAs(tap(new User, fn ($u) => $u->id = 2), 'second')
        ->run(Counter::class, [], fn ($counter) => expect(auth()->guard('second')->id())->toBe(2));

    expect(auth()->guard('second')->user())->toBeNull()
        ->and(auth()->user())->toBe($onDefault);
});

it('keeps the acting user out of the next row when the component cannot be resolved', function () {
    $original = new User;
    $original->id = 7;
    auth()->setUser($original);

    expect(fn () => Wireless::actingAs(new User)->run('no-such-component', [], fn ($driver) => null))
        ->toThrow(ComponentNotFoundException::class);

    expect(auth()->user())->toBe($original);
});

it('gives the shared state back when a driver is dropped without finishing', function () {
    $before = app('redirect');

    (function (): void {
        Wireless::component(Counter::class)->mount()->call('increment');
    })();

    gc_collect_cycles();

    // the destructor is the backstop: one forgotten finish() used to pin the shared state for the
    // life of the process, so no later cycle would ever restore the redirector again
    expect(app('redirect'))->toBe($before)
        ->and(HandleComponents::$componentStack)->toBe([]);
});
