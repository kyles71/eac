<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StaffNotes\Tables;

use App\Models\StaffNote;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StaffNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['author', 'media']))
            ->columns([
                TextColumn::make('note')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(3),
                TextColumn::make('author.full_name')
                    ->label('Author')
                    ->placeholder('Deleted user'),
                TextColumn::make('document_count')
                    ->label('Documents')
                    ->state(fn (StaffNote $record): int => $record->getMedia('documents')->count()),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ], RecordActionsPosition::BeforeCells);
    }
}
