<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Tables;

use App\Models\FormAssignment;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FormUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();

                return FormAssignment::query()
                    ->select('form_assignments.*')
                    ->with(['form', 'subject', 'version', 'latestSubmittedResponse'])
                    ->when(
                        $user instanceof User,
                        fn ($query) => $query->forRespondent($user),
                        fn ($query) => $query->whereRaw('1 = 0'),
                    );
            })
            ->columns([
                TextColumn::make('form.name')
                    ->searchable(),
                TextColumn::make('subject_label')
                    ->label('Student')
                    ->state(fn (FormAssignment $record): string => self::modelLabel($record->subject)),
                TextColumn::make('version.version')
                    ->label('Version'),
                TextColumn::make('latestSubmittedResponse.date_signed')
                    ->date()
                    ->label('Date Signed'),
                TextColumn::make('status')
                    ->state(fn (FormAssignment $record): string => $record->isCompleted() ? 'Completed' : 'Pending')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Completed' ? 'success' : 'warning'),
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
            ->recordActions([]);
    }

    private static function modelLabel(?\Illuminate\Database\Eloquent\Model $model): string
    {
        if ($model === null) {
            return '-';
        }

        if (method_exists($model, 'displayName')) {
            return (string) $model->displayName();
        }

        if (filled($model->getAttribute('fullName'))) {
            return (string) $model->getAttribute('fullName');
        }

        if (filled($model->getAttribute('name'))) {
            return (string) $model->getAttribute('name');
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
