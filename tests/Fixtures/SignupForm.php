<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Attributes\Validate;
use Livewire\Form;

class SignupForm extends Form
{
    #[Validate('required|min:2')]
    public string $name = '';

    #[Validate('required|email')]
    public string $email = '';

    /** Proves the form object's own hooks run, not just the component's. */
    public bool $touched = false;

    public function updatedName(): void
    {
        $this->touched = true;
    }
}
