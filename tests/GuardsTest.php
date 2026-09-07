<?php

declare(strict_types=1);

use RobertStanciu\Wireless\Exceptions\ComponentNotMounted;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;

it('says so when a component was never mounted', function (Closure $use) {
    $driver = Wireless::component(Counter::class);

    expect(fn () => $use($driver))
        ->toThrow(ComponentNotMounted::class, 'has not been mounted');
})->with([
    'set' => [fn ($driver) => $driver->set('count', 1)],
    'get' => [fn ($driver) => $driver->get('count')],
    'call' => [fn ($driver) => $driver->call('increment')],
    'errors' => [fn ($driver) => $driver->errors()],
    'effects' => [fn ($driver) => $driver->effects()],
    'dispatches' => [fn ($driver) => $driver->dispatches()],
    'redirect' => [fn ($driver) => $driver->redirect()],
    'instance' => [fn ($driver) => $driver->instance()],
]);

it('names the component in the message, so the trace is not a guessing game', function () {
    expect(fn () => Wireless::component(Counter::class)->get('count'))
        ->toThrow(ComponentNotMounted::class, Counter::class);
});

it('lets finish() pass quietly when nothing was mounted', function () {
    $driver = Wireless::component(Counter::class);

    expect($driver->finish())->toBe($driver);
});
