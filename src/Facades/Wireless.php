<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use RobertStanciu\Wireless\ComponentDriver;
use RobertStanciu\Wireless\Wireless as Manager;

/**
 * @method static mixed run(string $component, array|Closure $params = [], ?Closure $callback = null)
 * @method static ComponentDriver component(string $component)
 *
 * @see Manager
 */
class Wireless extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
