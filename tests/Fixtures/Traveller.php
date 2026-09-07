<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;

class Traveller extends Component
{
    public function toPath(): void
    {
        $this->redirect('/somewhere');
    }

    public function toRoute(): void
    {
        $this->redirectRoute('wireless-test-destination');
    }

    public function withNavigate(): void
    {
        $this->redirect('/spa', navigate: true);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
