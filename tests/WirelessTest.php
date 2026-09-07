<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use RobertStanciu\Wireless\Exceptions\ComponentNotMounted;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;

it('runs the mount cycle with its parameters', function () {
    $count = Wireless::run(Counter::class, ['start' => 5, 'label' => 'visits'], function ($counter) {
        expect($counter->get('label'))->toBe('visits')
            // mount() itself ran, not just the constructor
            ->and($counter->get('mounted'))->toBeTrue();

        return $counter->get('count');
    });

    expect($count)->toBe(5);
});

it('updates properties through livewire, so the updated hook fires', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        $counter->set('count', 7);

        expect($counter->get('count'))->toBe(7)
            ->and($counter->get('lastUpdate'))->toBe(7);
    });
});

it('sets several properties at once', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        $counter->set(['count' => 2, 'label' => 'batched']);

        expect($counter->get('count'))->toBe(2)
            ->and($counter->get('label'))->toBe('batched');
    });
});

it('calls methods and keeps what they returned', function () {
    Wireless::run(Counter::class, ['start' => 1], function ($counter) {
        expect($counter->call('increment', 4)->returned())->toBe(5)
            ->and($counter->get('count'))->toBe(5);
    });
});

it('refuses a method the browser could not call either', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        expect(fn () => $counter->call('hidden'))->toThrow(MethodNotFoundException::class);
    });
});

it('surfaces failed validation as an exception', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        expect(fn () => $signup->set('email', 'not-an-email')->call('save'))
            ->toThrow(ValidationException::class);
    });
});

it('can keep validation errors in the bag instead of throwing', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        $signup->keepValidationErrors()->set('email', '')->call('save');

        expect($signup->errors()->has('email'))->toBeTrue();
    });
});

it('passes validation like a real request would', function () {
    $result = Wireless::run(Signup::class, [], fn ($signup) => $signup
        ->set('email', 'someone@example.com')
        ->call('save')
        ->returned());

    expect($result)->toBe('saved: someone@example.com');
});

it('reports where the component redirected', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        $counter->call('leave');

        expect($counter->redirect())->toBe('/somewhere');
    });
});

it('gives the real redirector back after the cycle', function () {
    $before = app('redirect');

    Wireless::run(Counter::class, [], fn ($counter) => $counter->call('increment'));

    expect(app('redirect'))->toBe($before);
});

it('gives the real redirector back even when the callback throws', function () {
    $before = app('redirect');

    expect(fn () => Wireless::run(Counter::class, [], function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(app('redirect'))->toBe($before);
});

it('says so when a component was never mounted', function () {
    expect(fn () => Wireless::component(Counter::class)->call('increment'))
        ->toThrow(ComponentNotMounted::class);
});

it('can be driven without run(), as long as it is finished', function () {
    $counter = Wireless::component(Counter::class)->mount(['start' => 3]);

    expect($counter->call('increment', 2)->get('count'))->toBe(5);

    // idempotent, so a finally never needs a guard
    expect($counter->finish()->finish())->toBe($counter);
});
