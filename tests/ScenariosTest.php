<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Listener;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;

it('imports a batch through one component per row, collecting the rejects', function () {
    $rows = [
        ['form.name' => 'Ada', 'form.email' => 'ada@example.com'],
        ['form.name' => 'X', 'form.email' => 'nope'],
        ['form.name' => 'Grace', 'form.email' => 'grace@example.com'],
    ];

    $saved = [];
    $failed = [];

    foreach ($rows as $index => $row) {
        try {
            $saved[] = Wireless::run(Signup::class, [], fn ($signup) => $signup
                ->set($row)
                ->call('saveForm')
                ->returned());
        } catch (ValidationException $e) {
            $failed[$index] = array_keys($e->errors());
        }
    }

    expect($saved)->toBe([
        'saved: Ada <ada@example.com>',
        'saved: Grace <grace@example.com>',
    ])->and($failed)->toBe([1 => ['form.name', 'form.email']]);
});

it('delivers an event to the listener the component registered', function () {
    Wireless::run(Listener::class, [], function ($listener) {
        $listener->call('__dispatch', 'order-placed', ['reference' => 'INV-1']);

        expect($listener->returned())->toBe('handled INV-1')
            ->and($listener->get('heard'))->toBe(['INV-1']);
    });
});

it('refuses an event the component does not listen for', function () {
    Wireless::run(Listener::class, [], function ($listener) {
        expect(fn () => $listener->call('__dispatch', 'never-heard-of-it', []))
            ->toThrow(EventHandlerDoesNotExist::class);
    });
});

it('lets an authorization failure through to the caller', function () {
    Wireless::run(Listener::class, [], function ($listener) {
        expect(fn () => $listener->call('guardedAction'))->toThrow(AuthorizationException::class);
    });
});

it('keeps two components apart when both are alive', function () {
    $first = Wireless::component(Counter::class)->mount(['start' => 1, 'label' => 'first']);
    $second = Wireless::component(Counter::class)->mount(['start' => 100, 'label' => 'second']);

    $first->call('increment');
    $second->call('increment', 5);

    expect($first->get('count'))->toBe(2)
        ->and($second->get('count'))->toBe(105)
        ->and($first->get('label'))->toBe('first')
        ->and($second->instance())->not->toBe($first->instance());

    $second->finish();
    $first->finish();
});

it('survives a long run of cycles without leaking state', function () {
    $total = 0;

    foreach (range(1, 25) as $i) {
        $total += Wireless::run(Counter::class, ['start' => $i], fn ($counter) => $counter
            ->call('increment')
            ->get('count'));
    }

    expect($total)->toBe(350)  // sum(2..26)
        ->and(app('livewire')->current())->toBeFalsy();
});
