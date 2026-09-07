<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RobertStanciu\Wireless\WirelessServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Livewire::test() renders, and rendering needs a key — the parity tests depend on it. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WirelessServiceProvider::class,
        ];
    }
}
