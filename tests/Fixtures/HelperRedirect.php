<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

/** Redirects through the helper, which resolves the container binding — not the store shortcut. */
class HelperRedirect extends Component
{
    public function leave(): void
    {
        redirect('/through-the-helper');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
