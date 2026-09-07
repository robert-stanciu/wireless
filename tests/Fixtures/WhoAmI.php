<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Reads the authenticated user during mount, the way a tenant-scoped component does. */
class WhoAmI extends Component
{
    public mixed $mountedAs = null;

    public function mount(): void
    {
        $this->mountedAs = Auth::id();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
