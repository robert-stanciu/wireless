<?php

declare(strict_types=1);

use RobertStanciu\Wireless\Exceptions\ComponentNotMountedException;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;

it('says so when a component was never mounted', function (Closure $use) {
    $driver = Wireless::component(Counter::class);

    expect(fn () => $use($driver))
        ->toThrow(ComponentNotMountedException::class, 'has not been mounted');
})->with([
    'set' => [fn ($driver) => $driver->set('count', 1)],
    'get' => [fn ($driver) => $driver->get('count')],
    'call' => [fn ($driver) => $driver->call('increment')],
    'set (array form)' => [fn ($driver) => $driver->set(['count' => 1])],
    'effects' => [fn ($driver) => $driver->effects()],
    'instance' => [fn ($driver) => $driver->instance()],
    'dispatch' => [fn ($driver) => $driver->dispatch('anything')],
    'redirect' => [fn ($driver) => $driver->redirect()],
    'dispatched' => [fn ($driver) => $driver->dispatched()],
    'errors' => [fn ($driver) => $driver->errors()],
]);

it('refuses to answer for a cycle that never happened', function () {
    $driver = Wireless::component(Counter::class);

    // an empty bag and a null redirect are what SUCCESS looks like — a caller inspecting a row
    // that never got as far as a component must not read them as one
    expect($driver->mounted())->toBeFalse()
        ->and(fn () => $driver->redirect())->toThrow(ComponentNotMountedException::class)
        ->and(fn () => $driver->dispatched())->toThrow(ComponentNotMountedException::class)
        ->and(fn () => $driver->errors())->toThrow(ComponentNotMountedException::class);
});

it('keeps the outcome readable after the cycle is over', function () {
    $driver = Wireless::component(Traveller::class)->mount();

    $driver->call('toPath')->finish();

    // run() tears down before the caller can read anything, so the last state has to survive it
    expect($driver->mounted())->toBeFalse()
        ->and($driver->redirect())->toBe('/somewhere');
});

it('names the component in the message, so the trace is not a guessing game', function () {
    expect(fn () => Wireless::component(Counter::class)->get('count'))
        ->toThrow(ComponentNotMountedException::class, Counter::class);
});

it('lets finish() pass quietly when nothing was mounted', function () {
    $driver = Wireless::component(Counter::class);

    expect($driver->finish())->toBe($driver);
});
