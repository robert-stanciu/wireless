<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use DomainException;
use Livewire\Component;

/** Adds an error and then fails for an unrelated reason — the real exception must survive. */
class Leaky extends Component
{
    public function save(): void
    {
        $this->addError('field', 'a message');

        throw new DomainException('the real failure');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
