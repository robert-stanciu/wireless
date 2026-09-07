<?php

declare(strict_types=1);

use RobertStanciu\Wireless\Exceptions\ComponentNotMounted;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;

it('says so when a component was never mounted', function (Closure $use) {
    $driver = Wireless::component(Counter::class);

    expect(fn () => $use($driver))
        ->toThrow(ComponentNotMounted::class, 'has not been mounted');
})->with([
    'set' => [fn ($driver) => $driver->set('count', 1)],
    'get' => [fn ($driver) => $driver->get('count')],
    'call' => [fn ($driver) => $driver->call('increment')],
    'set (array form)' => [fn ($driver) => $driver->set(['count' => 1])],
    'effects' => [fn ($driver) => $driver->effects()],
    'instance' => [fn ($driver) => $driver->instance()],
    'dispatch' => [fn ($driver) => $driver->dispatch('anything')],
]);

it('answers the read-only questions before a mount instead of throwing', function () {
    $driver = Wireless::component(Counter::class);

    expect($driver->mounted())->toBeFalse()
        ->and($driver->redirect())->toBeNull()
        ->and($driver->dispatches())->toBe([])
        ->and($driver->errors()->isEmpty())->toBeTrue()
        ->and($driver->returned())->toBeNull();
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
        ->toThrow(ComponentNotMounted::class, Counter::class);
});

it('lets finish() pass quietly when nothing was mounted', function () {
    $driver = Wireless::component(Counter::class);

    expect($driver->finish())->toBe($driver);
});
