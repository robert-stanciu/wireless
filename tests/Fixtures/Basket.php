<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Arrays, collections and dotted paths — the values that go through Livewire's synthesizers
 * rather than being plain scalars.
 */
class Basket extends Component
{
    /** @var array<int, array{sku: string, qty: int}> */
    public array $lines = [
        ['sku' => 'A-1', 'qty' => 1],
    ];

    /** @var Collection<int, string> */
    public Collection $tags;

    /** @var array<int, string> */
    public array $updates = [];

    public function mount(): void
    {
        $this->tags = collect(['new']);
    }

    /** The catch-all hook: fires for any property, with the path the browser would send. */
    public function updated(string $path): void
    {
        $this->updates[] = $path;
    }

    public function total(): int
    {
        return collect($this->lines)->sum('qty');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
