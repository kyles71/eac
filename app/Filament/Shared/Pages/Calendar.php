<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Widgets\CalendarWidget;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class Calendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return AdminNavigation::ClassesAndSchedule;
        }

        return parent::getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return AdminNavigation::ScheduleCalendar;
        }

        return parent::getNavigationSort();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CalendarWidget::class,
        ];
    }
}
