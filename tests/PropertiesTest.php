<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Basket;
use RobertStanciu\Wireless\Tests\Fixtures\Cascading;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Guarded;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;

it('updates a property through livewire, so the updated hook fires', function () {
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

it('writes into a nested array by path, like wire:model does', function () {
    Wireless::run(Basket::class, [], function ($basket) {
        $basket->set('lines.0.qty', 4);

        expect($basket->get('lines.0.qty'))->toBe(4)
            ->and($basket->call('total')->returned())->toBe(4);
    });
});

it('appends to an array by path', function () {
    Wireless::run(Basket::class, [], function ($basket) {
        $basket->set('lines.1', ['sku' => 'B-2', 'qty' => 3]);

        expect($basket->get('lines'))->toHaveCount(2)
            ->and($basket->call('total')->returned())->toBe(4);
    });
});

it('round-trips a collection through livewire synthesizers', function () {
    Wireless::run(Basket::class, [], function ($basket) {
        $basket->set('tags', collect(['sale', 'new']));

        expect($basket->get('tags'))->toBeInstanceOf(Collection::class)
            ->and($basket->get('tags')->all())->toBe(['sale', 'new']);
    });
});

it('reports the updated path to the catch-all hook', function () {
    Wireless::run(Basket::class, [], function ($basket) {
        $basket->set('lines.0.qty', 9)->set('tags', collect([]));

        expect($basket->get('updates'))->toBe(['lines.0.qty', 'tags']);
    });
});

it('updates a property on a form object, hooks included', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        $signup->set('form.name', 'Ada');

        expect($signup->get('form.name'))->toBe('Ada')
            // the form object's own updatedName() ran
            ->and($signup->get('form.touched'))->toBeTrue();
    });
});

it('refuses to write a locked property, exactly as a request would', function () {
    Wireless::run(Guarded::class, [], function ($guarded) {
        expect(fn () => $guarded->set('ownerId', 99))
            ->toThrow(CannotUpdateLockedPropertyException::class)
            ->and($guarded->get('ownerId'))->toBe(1);
    });
});

it('reads a computed property and caches it for the cycle', function () {
    Wireless::run(Guarded::class, [], function ($guarded) {
        $component = $guarded->instance();

        expect($component->doubled)->toBe(4)
            ->and($component->doubled)->toBe(4)
            ->and($guarded->get('computes'))->toBe(1);
    });
});

it('applies a multi-key set one key at a time, like Livewire::test() does', function () {
    // updatedCountry() blanks the city, so the order of writes and hooks is observable —
    // whatever Livewire's own testing API ends up with is the answer this driver has to match
    $throughLivewire = Livewire::test(Cascading::class)
        ->set(['country' => 'RO', 'city' => 'Cluj'])
        ->get('city');

    Wireless::run(Cascading::class, [], function ($form) use ($throughLivewire) {
        $form->set(['country' => 'RO', 'city' => 'Cluj']);

        expect($form->get('city'))->toBe($throughLivewire)
            ->and($form->get('country'))->toBe('RO');
    });
});

it('leaves the earlier keys written when a later one is refused', function () {
    Wireless::run(Guarded::class, [], function ($guarded) {
        expect(fn () => $guarded->set(['multiplier' => 5, 'ownerId' => 99]))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        // half-applied, exactly as a request would leave it — pinned so nobody "fixes" it quietly
        expect($guarded->get('multiplier'))->toBe(5)
            ->and($guarded->get('ownerId'))->toBe(1);
    });
});

it('returns null for a property that is not there', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        expect($counter->get('nope'))->toBeNull()
            ->and($counter->get('deeply.nested.nope'))->toBeNull();
    });
});
