<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

class Signup extends Component
{
    public string $email = '';

    public SignupForm $form;

    public function save(): string
    {
        $this->validate(['email' => ['required', 'email']]);

        return "saved: {$this->email}";
    }

    /** Validation through a form object — its own rules, its own error keys. */
    public function saveForm(): string
    {
        $this->form->validate();

        return "saved: {$this->form->name} <{$this->form->email}>";
    }

    public function untouched(): string
    {
        return 'nothing to validate';
    }

    /** An error added by hand, not by the validator. */
    public function reject(): void
    {
        $this->addError('email', 'Taken already.');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
