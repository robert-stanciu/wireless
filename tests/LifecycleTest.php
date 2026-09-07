<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use RobertStanciu\Wireless\ComponentDriver;
use RobertStanciu\Wireless\Exceptions\ComponentAlreadyMountedException;
use RobertStanciu\Wireless\Exceptions\ComponentNotMountedException;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Lifecycle;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Wireless as Manager;

it('hands run() the driver and returns what the callback returned', function () {
    $returned = Wireless::run(Counter::class, ['start' => 2], function ($counter) {
        expect($counter)->toBeInstanceOf(ComponentDriver::class);

        return 'from the callback';
    });

    expect($returned)->toBe('from the callback');
});

it('can be driven without run(), as long as it is finished', function () {
    $counter = Wireless::component(Counter::class)->mount(['start' => 3]);

    expect($counter->call('increment', 2)->get('count'))->toBe(5);

    $counter->finish();
});

it('finishes idempotently, so a finally never needs a guard', function () {
    $counter = Wireless::component(Counter::class)->mount();

    expect($counter->finish()->finish())->toBe($counter);
});

it('is done with the component once finished', function () {
    $counter = Wireless::component(Counter::class)->mount();

    $counter->finish();

    expect(fn () => $counter->get('count'))->toThrow(ComponentNotMountedException::class);
});

it('can be mounted again after finishing', function () {
    $counter = Wireless::component(Counter::class);

    expect($counter->mount(['start' => 1])->get('count'))->toBe(1);

    $counter->finish();

    expect($counter->mount(['start' => 9])->get('count'))->toBe(9);

    $counter->finish();
});

it('tears the cycle down even when the callback throws', function () {
    $redirector = app('redirect');

    expect(fn () => Wireless::run(Counter::class, [], function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(app('redirect'))->toBe($redirector)
        ->and(app('livewire')->current())->toBeFalsy()
        ->and(HandleComponents::$componentStack)->toBe([]);
});

it('shrugs when the callback finishes the driver itself', function () {
    $returned = Wireless::run(Counter::class, ['start' => 2], function ($counter) {
        $counter->call('increment')->finish();

        return $counter->returned();
    });

    expect($returned)->toBe(3);
});

it('refuses a second mount while the first is still open', function () {
    $driver = Wireless::component(Counter::class)->mount();

    expect(fn () => $driver->mount())->toThrow(ComponentAlreadyMountedException::class);

    $driver->finish();
});

it('is built for the livewire major that is actually installed', function () {
    $installed = (int) ltrim(explode('.', InstalledVersions::getPrettyVersion('livewire/livewire'))[0], 'v');

    expect(Lifecycle::LIVEWIRE_MAJOR)->toBe($installed);
});

it('hands out a fresh driver for every component', function () {
    $one = Wireless::component(Counter::class);
    $two = Wireless::component(Counter::class);

    expect($one)->not->toBe($two);
});

it('resolves the manager as a singleton', function () {
    expect(app(Manager::class))->toBe(app(Manager::class))
        ->and(Wireless::getFacadeRoot())->toBe(app(Manager::class));
});

it('exposes the component itself for anything the driver does not cover', function () {
    Wireless::run(Counter::class, ['label' => 'direct'], function ($counter) {
        expect($counter->instance())->toBeInstanceOf(Counter::class)
            ->and($counter->instance()->label)->toBe('direct')
            ->and($counter->instance()->getId())->not->toBeEmpty();
    });
});

it('lets the mount parameters be left out', function () {
    expect(Wireless::run(Counter::class, fn ($counter) => $counter->get('label')))->toBe('counter');
});

it('refuses to run without a callback', function () {
    expect(fn () => Wireless::run(Counter::class, ['start' => 1]))
        ->toThrow(InvalidArgumentException::class, 'needs a callback');
});
