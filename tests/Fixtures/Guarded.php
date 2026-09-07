<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Guarded extends Component
{
    #[Locked]
    public int $ownerId = 1;

    public int $multiplier = 2;

    /** Counts the computes, so a test can prove the value is cached like it is in a request. */
    public int $computes = 0;

    #[Computed]
    public function doubled(): int
    {
        $this->computes++;

        return $this->multiplier * 2;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
