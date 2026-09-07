<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use DomainException;
use Livewire\Component;

/** A component whose mount() fails — the path that used to leave global state behind. */
class Exploding extends Component
{
    public function mount(): void
    {
        throw new DomainException('mount blew up');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
