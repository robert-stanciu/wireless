<?php

declare(strict_types=1);

use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Livewire;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Hooked;
use RobertStanciu\Wireless\Tests\Fixtures\Injected;
use RobertStanciu\Wireless\Tests\Fixtures\Lazy;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;
use RobertStanciu\Wireless\Tests\Fixtures\SignupForm;

it('runs the mount cycle with its parameters', function () {
    Wireless::run(Counter::class, ['start' => 5, 'label' => 'visits'], function ($counter) {
        expect($counter->get('label'))->toBe('visits')
            ->and($counter->get('count'))->toBe(5)
            // mount() itself ran, not just the constructor
            ->and($counter->get('mounted'))->toBeTrue();
    });
});

it('falls back to the parameter defaults when none are given', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        expect($counter->get('count'))->toBe(0)
            ->and($counter->get('label'))->toBe('counter');
    });
});

it('accepts a registered alias as well as a class name', function () {
    Livewire::component('counter-alias', Counter::class);

    Wireless::run('counter-alias', ['start' => 2], function ($counter) {
        expect($counter->instance())->toBeInstanceOf(Counter::class)
            ->and($counter->get('count'))->toBe(2);
    });
});

it('mounts a lazy component for real instead of its placeholder', function () {
    // no browser will come back for the real thing, so the placeholder would be a dead end
    Wireless::run(Lazy::class, [], function ($lazy) {
        expect($lazy->get('state'))->toBe('mounted for real');
    });
});

it('resolves mount dependencies out of the container', function () {
    Wireless::run(Injected::class, ['name' => 'Ada'], function ($injected) {
        expect($injected->get('greeting'))->toBe('hello Ada');
    });
});

it('initialises form objects like a real mount does', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        expect($signup->instance()->form)->toBeInstanceOf(SignupForm::class)
            ->and($signup->get('form.email'))->toBe('');
    });
});

it('complains about a component that does not exist', function () {
    expect(fn () => Wireless::run('no-such-component', [], fn () => null))
        ->toThrow(ComponentNotFoundException::class);
});

it('fires the lifecycle hooks a real mount fires, in order', function () {
    Wireless::run(Hooked::class, [], function ($hooked) {
        // hydrate belongs to a request that rebuilds a component from a snapshot; there is none here
        expect($hooked->get('trace'))->toBe(['boot', 'mount', 'booted']);
    });
});

it('mounts one component inside another and leaves the outer one usable', function () {
    Wireless::run(Counter::class, ['start' => 1], function ($outer) {
        $outer->call('increment');

        $inner = Wireless::run(Counter::class, ['start' => 10], fn ($nested) => $nested
            ->call('increment')
            ->get('count'));

        // the inner teardown must not take the outer cycle's state with it
        expect($inner)->toBe(11)
            ->and($outer->call('increment')->get('count'))->toBe(3)
            ->and(app('livewire')->current())->toBe($outer->instance());
    });
});
