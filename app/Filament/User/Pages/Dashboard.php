<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Filament\Shared\Pages\Calendar;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;
use App\Filament\User\Resources\Students\StudentResource;
use App\Filament\User\Widgets\ComingUp;
use App\Filament\User\Widgets\NeedsAttention;
use App\Filament\User\Widgets\NextPayment;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

final class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'My Dashboard';

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
            NeedsAttention::class,
            MessagesFromEac::class,
            QuickLinks::class,
            ComingUp::class,
            NextPayment::class,
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('browseStore')
                ->label('Browse Store')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->url(Store::getUrl()),
            Action::make('viewCalendar')
                ->label('View Calendar')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(Calendar::getUrl()),
            Action::make('addStudent')
                ->label('Add Student')
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(StudentResource::getUrl('create')),
            Action::make('billing')
                ->icon(Heroicon::OutlinedCreditCard)
                ->url(Billing::getUrl()),
        ];
    }
}
