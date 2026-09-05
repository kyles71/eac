<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards\Schemas;

use App\Enums\BoardStageKind;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class BoardStageForm
{
    /** @return array<int, TextInput|Select> */
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(80),
            TextInput::make('subtitle')
                ->maxLength(160),
            Select::make('color')
                ->options(BoardForm::colorOptions())
                ->required(),
            Select::make('kind')
                ->options(BoardStageKind::class)
                ->enum(BoardStageKind::class)
                ->required(),
        ];
    }
}
