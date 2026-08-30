<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\SubstituteCoverageReminder;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;

final class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Admin Dashboard';

    public function getHeading(): string
    {
        return 'Hello, '.auth()->user()->first_name.'!';
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            SubstituteCoverageReminder::class,
            MessagesFromEac::class,
            QuickLinks::class,
            CalendarWidget::class,
        ];
    }

    public function getColumns(): array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }
}
