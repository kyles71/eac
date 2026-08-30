<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Role;
use App\Models\User;
use App\Services\AcademicTermService;
use App\Settings\AcademicTermSettings;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class AcademicTermDefaults extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string $settings = AcademicTermSettings::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsAcademicTermDefaults;

    protected static ?string $navigationLabel = 'Academic Term Defaults';

    protected static ?string $title = 'Academic Term Defaults';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recurring Start Dates')
                    ->description('These month-and-day defaults generate upcoming terms automatically. Existing current, past, and manually overridden terms are preserved.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        self::startDateInput('winter_spring_starts_on', 'Winter-Spring Starts'),
                        self::startDateInput('summer_starts_on', 'Summer Starts')
                            ->after('winter_spring_starts_on'),
                        self::startDateInput('fall_starts_on', 'Fall Starts')
                            ->after('summer_starts_on'),
                    ]),
            ]);
    }

    protected function afterSave(): void
    {
        app(AcademicTermService::class)->sync();
    }

    private static function startDateInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->helperText('Enter a recurring date as MM-DD.')
            ->placeholder('MM-DD')
            ->rules(['date_format:m-d'])
            ->maxLength(5)
            ->required();
    }
}
