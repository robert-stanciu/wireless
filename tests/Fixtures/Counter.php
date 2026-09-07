<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    public string $label = '';

    /** Set by mount() so a test can prove the mount cycle ran. */
    public bool $mounted = false;

    /** Set by the updated hook, which only fires when a property goes through Livewire. */
    public ?int $lastUpdate = null;

    public function mount(int $start = 0, string $label = 'counter'): void
    {
        $this->count = $start;
        $this->label = $label;
        $this->mounted = true;
    }

    public function updatedCount(int $value): void
    {
        $this->lastUpdate = $value;
    }

    public function increment(int $by = 1): int
    {
        $this->count += $by;

        return $this->count;
    }

    public function leave(): void
    {
        $this->redirect('/somewhere');
    }

    protected function hidden(): string
    {
        return 'not callable from outside';
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}
