<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards\Schemas;

use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Services\Boards\BoardMembershipService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Illuminate\Support\HtmlString;

final class BoardMembershipForm
{
    public static function make(Board $board): Repeater
    {
        return Repeater::make('memberships')
            ->label('Board members')
            ->table([
                TableColumn::make('Member')->markAsRequired(),
                TableColumn::make(new HtmlString(
                    view('filament.admin.resources.boards.components.membership-role-heading')->render(),
                )),
            ])
            ->schema([
                Select::make('user_id')
                    ->label('Member')
                    ->options(fn (): array => app(BoardMembershipService::class)->eligibleUserOptions($board))
                    ->searchable()
                    ->preload()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->required(),
                Select::make('role')
                    ->options(BoardMemberRole::class)
                    ->enum(BoardMemberRole::class)
                    ->default(BoardMemberRole::Contributor->value)
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->addActionLabel('Add member')
            ->reorderable(false)
            ->defaultItems(0)
            ->columnSpanFull();
    }
}
