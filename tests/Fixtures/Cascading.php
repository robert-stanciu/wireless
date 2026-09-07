<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

/** An updated hook that overwrites another property, so the order of writes and hooks is visible. */
class Cascading extends Component
{
    public string $country = '';

    public string $city = '';

    public function updatedCountry(): void
    {
        $this->city = '';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
