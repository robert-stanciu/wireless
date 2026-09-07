<?php

declare(strict_types=1);

use Illuminate\Pagination\Paginator;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Delegating;
use RobertStanciu\Wireless\Tests\Fixtures\Traveller;

afterEach(function () {
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
});

it('does not flush livewire state while a render is in flight', function () {
    // the page has already rendered a component, so Livewire's per-request state belongs to it
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;

    Wireless::run(Counter::class, [], fn ($counter) => $counter->call('increment'));

    expect(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)->toBeTrue();
});

it('does not flush livewire state during a livewire update request', function () {
    request()->headers->set('X-Livewire', 'true');
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;

    Wireless::run(Counter::class, [], fn ($counter) => $counter->call('increment'));

    expect(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest)->toBeTrue();

    request()->headers->remove('X-Livewire');
});

it('gives the redirector back to the cycle that owns the request', function () {
    // a component that drives another one and then redirects itself: the inner teardown used to
    // leave the container bound to the finished child, so the parent's redirect went nowhere
    $redirect = Wireless::run(Delegating::class, [], fn ($parent) => $parent
        ->call('delegateThenRedirect')
        ->redirect());

    expect($redirect)->toBe('/parent-went-here');
});

it('does not blame a component for the failure of one it drives', function () {
    Wireless::run(Delegating::class, [], function ($outer) {
        expect($outer->call('delegate')->returned())->toBe('delegated cleanly');
    });
});

it('unwinds only its own frames when a foreign one is on the stack', function () {
    SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;   // nothing sweeps for us

    $driver = Wireless::component(Counter::class)->mount();

    HandleComponents::$componentStack[] = 'a frame that belongs to the request';

    $driver->finish();

    expect(HandleComponents::$componentStack)->toBe(['a frame that belongs to the request']);

    HandleComponents::$componentStack = [];
});

it('runs the destroy hooks, so global state a component changed is put back', function () {
    // SupportPagination::destroy() restores these; a component driven without it would leave the
    // application rendering Livewire's pagination views for good
    Paginator::defaultView('the-app-view');
    Paginator::defaultSimpleView('the-app-simple-view');

    Wireless::run(Counter::class, [], fn ($counter) => $counter->call('increment'));

    expect(Paginator::$defaultView)->toBe('the-app-view')
        ->and(Paginator::$defaultSimpleView)->toBe('the-app-simple-view');
});

it('keeps the callers exception when a destroy hook throws too', function () {
    $stopListening = Livewire\on('destroy', function (): void {
        throw new LogicException('the destroy hook blew up');
    });

    try {
        expect(fn () => Wireless::run(Traveller::class, [], function (): void {
            throw new RuntimeException('the real failure');
        }))->toThrow(RuntimeException::class, 'the real failure');

        expect(SupportRedirects::$redirectorCacheStack)->toBe([])
            ->and(HandleComponents::$componentStack)->toBe([]);
    } finally {
        $stopListening();
    }
});
