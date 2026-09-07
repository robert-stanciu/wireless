<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;
use RobertStanciu\Wireless\Facades\Wireless;

/** A component that drives another one — the use case the README leads with. */
class Delegating extends Component
{
    public ?string $childRedirect = null;

    public function delegate(): string
    {
        // the inner component fails validation; the outer call must not be blamed for it
        Wireless::run(Signup::class, [], fn ($signup) => $signup->keepValidationErrors()->call('save'));

        return 'delegated cleanly';
    }

    public function delegateThenRedirect(): void
    {
        $this->childRedirect = Wireless::run(Traveller::class, [], fn ($t) => $t->call('toPath')->redirect());

        $this->redirect('/parent-went-here');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
