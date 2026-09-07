<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

/** Validates on update — the commonest Livewire form idiom, and one Livewire swallows into the bag. */
class LiveValidated extends Component
{
    public string $email = '';

    public function updatedEmail(): void
    {
        $this->validateOnly('email', ['email' => ['required', 'email']]);
    }

    public function save(): string
    {
        return "saved: {$this->email}";
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
