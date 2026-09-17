<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Tables;

use App\Models\FormAssignment;
use App\Models\Student;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FormUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();

                $query = FormAssignment::query();

                return $query
                    ->select($query->getModel()->qualifyColumn('*'))
                    ->with(['form', 'subject', 'version', 'latestSubmittedResponse'])
                    ->when(
                        $user instanceof User,
                        fn ($query) => $query->accessibleBy($user),
                        fn ($query) => $query->whereRaw('1 = 0'),
                    );
            })
            ->columns([
                TextColumn::make('form.name')
                    ->searchable(),
                TextColumn::make('subject_label')
                    ->label('Student')
                    ->state(fn (FormAssignment $record): string => self::modelLabel($record->subject))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHasMorph(
                            'subject',
                            Student::class,
                            fn (Builder $query): Builder => $query
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%"),
                        )),
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
