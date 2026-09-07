<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RobertStanciu\Wireless\WirelessServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WirelessServiceProvider::class,
        ];
    }
}
