<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

/** Records the lifecycle hooks the cycle fires, and in which order. */
class Hooked extends Component
{
    /** @var array<int, string> */
    public array $trace = [];

    public function boot(): void
    {
        $this->trace[] = 'boot';
    }

    public function mount(): void
    {
        $this->trace[] = 'mount';
    }

    public function booted(): void
    {
        $this->trace[] = 'booted';
    }

    public function hydrate(): void
    {
        $this->trace[] = 'hydrate';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
