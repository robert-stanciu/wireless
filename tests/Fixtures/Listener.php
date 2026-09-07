<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\On;
use Livewire\Component;

class Listener extends Component
{
    /** @var array<int, string> */
    public array $heard = [];

    #[On('order-placed')]
    public function onOrderPlaced(string $reference): string
    {
        $this->heard[] = $reference;

        return "handled {$reference}";
    }

    public function guardedAction(): void
    {
        throw new AuthorizationException('This action is unauthorized.');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
