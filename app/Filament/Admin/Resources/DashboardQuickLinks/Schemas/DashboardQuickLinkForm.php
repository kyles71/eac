<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks\Schemas;

use App\Enums\DashboardAudience;
use App\Filament\User\Pages\Store;
use App\Services\DashboardQuickLinkDestinationService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class DashboardQuickLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dashboard Quick Link')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('label')
                            ->required()
                            ->maxLength(100),
                        Select::make('audience')
                            ->options(DashboardAudience::class)
                            ->enum(DashboardAudience::class)
                            ->default(DashboardAudience::Eac->value)
                            ->selectablePlaceholder(false)
                            ->required(),
                        Select::make('destination')
                            ->options(fn (): array => app(DashboardQuickLinkDestinationService::class)->options())
                            ->default(Store::class)
                            ->searchable()
                            ->preload()
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live(),
                        TextInput::make('external_url')
                            ->label('External URL')
                            ->url()
                            ->regex('/^https?:\/\//i')
                            ->required(fn (Get $get): bool => self::isExternal($get('destination')))
                            ->visible(fn (Get $get): bool => self::isExternal($get('destination')))
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }

    private static function isExternal(mixed $destination): bool
    {
        return app(DashboardQuickLinkDestinationService::class)->isExternal($destination);
    }
}
