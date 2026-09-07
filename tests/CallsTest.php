<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use RobertStanciu\Wireless\Facades\Wireless;
use RobertStanciu\Wireless\Tests\Fixtures\Broadcaster;
use RobertStanciu\Wireless\Tests\Fixtures\Counter;
use RobertStanciu\Wireless\Tests\Fixtures\Injected;
use RobertStanciu\Wireless\Tests\Fixtures\Signup;

it('calls a method and keeps what it returned', function () {
    Wireless::run(Counter::class, ['start' => 1], function ($counter) {
        expect($counter->call('increment', 4)->returned())->toBe(5)
            ->and($counter->get('count'))->toBe(5);
    });
});

it('chains calls, keeping only the last return', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        expect($counter->call('increment')->call('increment', 2)->returned())->toBe(3);
    });
});

it('returns null from a method that returns nothing', function () {
    Wireless::run(Broadcaster::class, [], function ($broadcaster) {
        expect($broadcaster->call('nothing')->returned())->toBeNull();
    });
});

it('resolves action dependencies out of the container', function () {
    Wireless::run(Injected::class, [], function ($injected) {
        expect($injected->call('again', 'Grace')->returned())->toBe('hello Grace');
    });
});

it('refuses a method the browser could not call either', function () {
    Wireless::run(Counter::class, [], function ($counter) {
        expect(fn () => $counter->call('hidden'))->toThrow(MethodNotFoundException::class);
    });
})->with([
    'protected' => 'hidden',
    'missing' => 'doesNotExist',
    'render' => 'render',
]);

it('lets the component dispatch to the browser', function () {
    Wireless::run(Broadcaster::class, [], function ($broadcaster) {
        $broadcaster->call('announce', 'ready');

        $dispatches = $broadcaster->dispatches();

        expect($dispatches)->toHaveCount(1)
            ->and($dispatches[0]['name'])->toBe('announced')
            ->and($dispatches[0]['params'])->toBe(['what' => 'ready']);
    });
});

it('lets an exception out of the component untouched', function () {
    Wireless::run(Broadcaster::class, [], function ($broadcaster) {
        expect(fn () => $broadcaster->call('explode'))
            ->toThrow(RuntimeException::class, 'the component threw');
    });
});

it('surfaces failed validation as an exception', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        expect(fn () => $signup->set('email', 'not-an-email')->call('save'))
            ->toThrow(ValidationException::class);
    });
});

it('surfaces an error the component added by hand', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        expect(fn () => $signup->call('reject'))->toThrow(ValidationException::class);
    });
});

it('validates through a form object and names the fields the way it does', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        try {
            $signup->set('form.email', 'nope')->call('saveForm');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKeys(['form.name', 'form.email']);

            return;
        }

        throw new RuntimeException('the form should not have validated');
    });
});

it('can keep validation errors in the bag instead of throwing', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        $signup->keepValidationErrors()->set('email', '')->call('save');

        expect($signup->errors()->has('email'))->toBeTrue()
            ->and($signup->returned())->toBeNull();
    });
});

it('passes validation like a real request would', function () {
    $result = Wireless::run(Signup::class, [], fn ($signup) => $signup
        ->set('email', 'someone@example.com')
        ->call('save')
        ->returned());

    expect($result)->toBe('saved: someone@example.com');
});

it('drives a form object end to end', function () {
    $result = Wireless::run(Signup::class, [], fn ($signup) => $signup
        ->set(['form.name' => 'Ada', 'form.email' => 'ada@example.com'])
        ->call('saveForm')
        ->returned());

    expect($result)->toBe('saved: Ada <ada@example.com>');
});

it('has an empty error bag until something fails', function () {
    Wireless::run(Signup::class, [], function ($signup) {
        expect($signup->errors()->isEmpty())->toBeTrue();
    });
});
