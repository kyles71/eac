<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages\Schemas;

use App\Enums\DashboardAudience;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DashboardMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dashboard Message')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('message')
                            ->required()
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('audience')
                            ->options(DashboardAudience::class)
                            ->enum(DashboardAudience::class)
                            ->default(DashboardAudience::Eac->value)
                            ->required()
                            ->helperText('Higher audiences automatically also see messages for lower audiences.'),
                        DateTimePicker::make('published_at')
                            ->label('Publish At')
                            ->timezone((string) config('app.display_timezone', config('app.timezone')))
                            ->helperText('Leave blank to publish immediately.'),
                        DateTimePicker::make('expires_at')
                            ->label('Expires At')
                            ->timezone((string) config('app.display_timezone', config('app.timezone')))
                            ->after('published_at')
                            ->helperText('Leave blank to keep the message visible indefinitely.'),
                    ]),
            ]);
    }
}
