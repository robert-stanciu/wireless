<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;
use RuntimeException;

class Broadcaster extends Component
{
    public function announce(string $what): void
    {
        $this->dispatch('announced', what: $what);
    }

    public function explode(): void
    {
        throw new RuntimeException('the component threw');
    }

    public function nothing(): void
    {
        //
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
