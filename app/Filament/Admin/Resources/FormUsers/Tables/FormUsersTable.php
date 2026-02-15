<?php

namespace App\Filament\Admin\Resources\FormUsers\Tables;

use App\Models\FormUser;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FormUsersTable
{
    public static function configure(Table $table, bool $only_my_forms = false): Table
    {
        return $table
            ->query(fn () => FormUser::query()
                ->when($only_my_forms, function ($query): void {
                    $query->where('user_id', auth()->id());
                })
            )
            ->columns([
                TextColumn::make('form.name')
                    ->searchable(),
                TextColumn::make('user.fullName')
                    ->hidden($only_my_forms)
                    ->searchable(),
                TextColumn::make('student.fullName')
                    ->searchable(),
                TextColumn::make('signature')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_signed')
                    ->date()
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
                //
            ])
            ->recordActions([

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
