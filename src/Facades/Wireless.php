<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Facades;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;
use RobertStanciu\Wireless\ComponentDriver;
use RobertStanciu\Wireless\Wireless as Manager;

/**
 * @method static mixed run(string $component, array<string, mixed>|Closure(ComponentDriver): mixed $params, ?Closure(ComponentDriver): mixed $callback = null)
 * @method static ComponentDriver component(string $component)
 * @method static Manager actingAs(Authenticatable $user, ?string $guard = null)
 *
 * @see Manager  for the generic return type — `app(Wireless::class)->run(...)` keeps it
 */
class Wireless extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
