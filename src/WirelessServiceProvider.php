<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless;

use Illuminate\Support\ServiceProvider;

class WirelessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Wireless::class);
    }
}
