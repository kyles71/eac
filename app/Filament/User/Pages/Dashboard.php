<?php

namespace App\Filament\User\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'My Dashboard';

    public function getHeading(): string
    {
        return 'Hello, ' . auth()->user()->first_name . '!';
    }
}
