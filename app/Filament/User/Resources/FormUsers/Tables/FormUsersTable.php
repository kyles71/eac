<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Tables;

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\FormUser;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FormUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => FormUser::query()
                ->select('form_users.*')
                ->where('user_id', auth()->id())
            )
            ->columns([
                TextColumn::make('form.name')
                    ->searchable(),
                TextColumn::make('student.fullName')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('signature')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_signed')
                    ->date()
                    ->label('Date Signed'),
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
                EditAction::make('update')
                    ->label('Update')
                    ->visible(fn (FormUser $record): bool => $record->form?->form_type !== FormTypes::StudentWaiver
                        && $record->formCanBeUpdated()),
                Action::make('reviseWaiver')
                    ->label('Update')
                    ->url(fn (FormUser $record): string => FormUserResource::getUrl('revise', ['record' => $record]))
                    ->visible(fn (FormUser $record): bool => $record->form?->form_type === FormTypes::StudentWaiver
                        && $record->formCanBeUpdated()),
            ]);
    }
}
