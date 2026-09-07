<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Tests\Fixtures;

use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Downloader extends Component
{
    public function export(): StreamedResponse
    {
        return response()->streamDownload(fn () => print ('id,name'), 'rows.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
