<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Tables;

use App\Models\FormUser;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FormUsersTable
{
    public static function configure(Table $table, bool $only_my_forms = false): Table
    {
        return $table
            ->query(fn () => FormUser::query()
                ->with(['form', 'student', 'user'])
                ->when($only_my_forms, function ($query): void {
                    $query->where('user_id', auth()->id());
                })
            )
            ->columns([
                TextColumn::make('form.name')
                    ->label('Form')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('completion_status')
                    ->label('Status')
                    ->state(fn (FormUser $record): string => $record->isCompleted() ? 'Completed' : 'Needs signature')
                    ->badge()
                    ->color(fn (FormUser $record): string => $record->isCompleted() ? 'success' : 'warning')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('user.full_name')
                    ->label('Parent / User')
                    ->hidden($only_my_forms)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('student.full_name')
                    ->label('Student')
                    ->placeholder('Family / user-level form')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('signature')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_signed')
                    ->date()
                    ->placeholder('Not signed')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('completion_status')
                    ->label('Status')
                    ->options([
                        'completed' => 'Completed',
                        'pending' => 'Needs signature',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'completed' => FormUser::applyCompletedConstraint($query),
                        'pending' => FormUser::applyPendingConstraint($query),
                        default => $query,
                    }),
                SelectFilter::make('form_id')
                    ->label('Form')
                    ->relationship('form', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
