<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Attributes\Lazy as LazyAttribute;
use Livewire\Component;

#[LazyAttribute]
class Lazy extends Component
{
    public string $state = 'not mounted';

    public function mount(): void
    {
        $this->state = 'mounted for real';
    }

    public function placeholder(): string
    {
        return '<div>loading…</div>';
    }

    public function render(): string
    {
        return '<div>{{ $state }}</div>';
    }
}
