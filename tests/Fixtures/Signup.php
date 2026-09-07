<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

class Signup extends Component
{
    public string $email = '';

    public function save(): string
    {
        $this->validate(['email' => ['required', 'email']]);

        return "saved: {$this->email}";
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
