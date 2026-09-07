<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Livewire\Component;

/** Properties whose values only a synthesizer can build from what the wire carries. */
class Typed extends Component
{
    public Suit $suit = Suit::Hearts;

    public ?CarbonImmutable $on = null;

    /** A property named like a reserved mount param — it must survive the mount untouched. */
    public string $defer = 'untouched';

    public function mount(string $lazy = 'a default nobody should overwrite'): void
    {
        $this->on = CarbonImmutable::parse('2020-01-01');
        $this->suit = Suit::Hearts;
        $this->lazyParam = $lazy;
    }

    public string $lazyParam = '';

    public function render(): string
    {
        return '<div></div>';
    }
}
