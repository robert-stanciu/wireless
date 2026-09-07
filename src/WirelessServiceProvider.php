<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Illuminate\Support\ServiceProvider;

use function Livewire\on;

class WirelessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Wireless::class);
    }

    public function boot(): void
    {
        // Livewire resets its own per-request state on this event (Octane, and between renders in
        // its test harness). Anything this package remembers about a cycle refers to that state,
        // so it has to go at the same moment.
        on('flush-state', ComponentDriver::forgetOpenCycles(...));
    }
}
