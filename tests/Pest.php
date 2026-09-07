<?php

declare(strict_types=1);

use Livewire\Features\SupportRedirects\Redirector;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use RobertStanciu\Wireless\Tests\TestCase;

// Every test here drives a real Livewire cycle against process-wide state, so "it passed" is only
// half the answer: a cycle that leaves a component on the stack, a frame on the redirector stack or
// Livewire's Redirector in the container has broken the NEXT unit of work, not this one. Asserted
// for every test rather than test by test, so a leak cannot slip in by forgetting to look.
uses(TestCase::class)
    ->afterEach(function () {
        expect(HandleComponents::$componentStack)->toBe([], 'a component was left on Livewire\'s stack')
            ->and(SupportRedirects::$redirectorCacheStack)->toBe([], 'a redirector frame was left behind')
            ->and(app('redirect'))->not->toBeInstanceOf(Redirector::class, 'the container kept Livewire\'s redirector');
    })
    ->in(__DIR__);
