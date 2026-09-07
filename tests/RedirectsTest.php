<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;

beforeEach(function () {
    Route::get('/destination', fn () => 'here')->name('wireless-test-destination');
});

it('reports where the component redirected', function () {
    Wireless::run(Traveller::class, [], function ($traveller) {
        $traveller->call('toPath');

        expect($traveller->redirect())->toBe('/somewhere');
    });
});

it('resolves a named route the way the component asked for it', function () {
    Wireless::run(Traveller::class, [], function ($traveller) {
        $traveller->call('toRoute');

        expect($traveller->redirect())->toEndWith('/destination');
    });
});

it('does not abort the surrounding request when a component redirects', function () {
    // SupportRedirects turns a redirect into abort(redirect(...)) during dehydrate, outside a
    // Livewire request — this cycle must never reach that hook
    $reached = false;

    Wireless::run(Traveller::class, [], function ($traveller) use (&$reached) {
        $traveller->call('withNavigate');

        $reached = true;
    });

    expect($reached)->toBeTrue();
});

it('has no redirect when the component never asked for one', function () {
    Wireless::run(Traveller::class, [], function ($traveller) {
        expect($traveller->redirect())->toBeNull();
    });
});

it('gives the real redirector back after the cycle', function () {
    $before = app('redirect');

    Wireless::run(Traveller::class, [], fn ($traveller) => $traveller->call('toPath'));

    expect(app('redirect'))->toBe($before);
});

it('gives the real redirector back even when the callback throws', function () {
    $before = app('redirect');

    expect(fn () => Wireless::run(Traveller::class, [], function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(app('redirect'))->toBe($before);
});

it('leaves the surrounding request able to redirect for real', function () {
    Wireless::run(Traveller::class, [], fn ($traveller) => $traveller->call('toPath'));

    expect(redirect('/after'))->toBeInstanceOf(RedirectResponse::class);
});

it('keeps the redirector intact across nested cycles', function () {
    $before = app('redirect');

    Wireless::run(Traveller::class, [], function () use ($before) {
        Wireless::run(Traveller::class, [], fn ($inner) => $inner->call('toPath'));

        // the inner finish() must not hand the outer cycle Laravel's redirector back early
        expect(app('redirect'))->not->toBe($before);
    });

    expect(app('redirect'))->toBe($before);
});

it('does not leak livewire state into the next cycle', function () {
    Wireless::run(Traveller::class, [], fn ($traveller) => $traveller->call('toPath'));

    expect(Livewire::isLivewireRequest())->toBeFalse()
        ->and(app('livewire')->current())->toBeFalsy();
});
