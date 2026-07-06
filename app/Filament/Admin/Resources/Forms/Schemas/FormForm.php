<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Schemas;

use App\Enums\FormPurpose;
use App\Enums\FormUpdateStrategy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        Select::make('purpose')
                            ->options(FormPurpose::class)
                            ->required(),
                        Toggle::make('updates_allowed')
                            ->label('Updates Allowed')
                            ->default(true)
                            ->required(),
                        Select::make('update_strategy')
                            ->options(FormUpdateStrategy::class)
                            ->default(FormUpdateStrategy::Revision)
                            ->required(),
                    ]),
            ]);
    }
}
