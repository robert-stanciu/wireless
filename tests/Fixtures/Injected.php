<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

/** Dependency injection has to work in mount() and in actions, exactly as in a request. */
class Injected extends Component
{
    public string $greeting = '';

    public function mount(Greeter $greeter, string $name = 'world'): void
    {
        $this->greeting = $greeter->greet($name);
    }

    public function again(Greeter $greeter, string $name): string
    {
        return $greeter->greet($name);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
