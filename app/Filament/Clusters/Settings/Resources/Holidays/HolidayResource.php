<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Holidays;

use App\Enums\HolidayEventScope;
use App\Filament\Clusters\Settings\Resources\Holidays\Pages\ManageHolidays;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Holiday;
use App\Services\HolidayConflictService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsHolidays;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Holiday')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        DatePicker::make('starts_on')
                            ->label('Starts On')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('confirm_conflict_deletion', false)),
                        DatePicker::make('ends_on')
                            ->label('Ends On')
                            ->required()
                            ->afterOrEqual('starts_on')
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('confirm_conflict_deletion', false)),
                        Select::make('scope')
                            ->label('Prevent Event Creation For')
                            ->options(HolidayEventScope::class)
                            ->enum(HolidayEventScope::class)
                            ->default(HolidayEventScope::AllEvents->value)
                            ->searchable(false)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('confirm_conflict_deletion', false))
                            ->columnSpanFull(),
                        TextEntry::make('conflict_summary')
                            ->label('Existing Event Conflicts')
                            ->state(fn (Get $get): string => self::conflictSummary($get))
                            ->visible(fn (Get $get): bool => self::conflictingEventCount($get) > 0)
                            ->color('danger')
                            ->columnSpanFull(),
                        Checkbox::make('confirm_conflict_deletion')
                            ->label('Permanently delete the conflicting events when this holiday is saved')
                            ->required(fn (Get $get): bool => self::conflictingEventCount($get) > 0)
                            ->accepted(fn (Get $get): bool => self::conflictingEventCount($get) > 0)
                            ->visible(fn (Get $get): bool => self::conflictingEventCount($get) > 0)
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label('Starts On')
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label('Ends On')
                    ->date()
                    ->sortable(),
                TextColumn::make('scope')
                    ->label('Prevent Event Creation For')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->successNotification(fn (Holiday $record): Notification => self::saveNotification($record, 'updated')),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHolidays::route('/'),
        ];
    }

    public static function saveNotification(Holiday $holiday, string $action): Notification
    {
        $deleted = $holiday->deletedConflictingEventsCount;

        return Notification::make()
            ->success()
            ->title("Holiday {$action}")
            ->body($deleted === 0
                ? 'No conflicting events were deleted.'
                : "{$deleted} conflicting ".str('event')->plural($deleted).' permanently deleted.');
    }

    private static function conflictSummary(Get $get): string
    {
        $count = self::conflictingEventCount($get);

        return "{$count} existing ".str('event')->plural($count).' will be permanently deleted.';
    }

    private static function conflictingEventCount(Get $get): int
    {
        return app(HolidayConflictService::class)->conflictingEventCountFor(
            $get('starts_on'),
            $get('ends_on'),
            $get('scope'),
        );
    }
}
