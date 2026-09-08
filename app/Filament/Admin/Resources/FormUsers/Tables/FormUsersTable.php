<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Tables;

use App\Models\FormAssignment;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FormUsersTable
{
    public static function configure(Table $table, bool $onlyMyForms = false): Table
    {
        return $table
            ->query(fn () => FormAssignment::query()
                ->with(['form', 'respondent', 'subject', 'version', 'latestSubmittedResponse'])
                ->when($onlyMyForms, function ($query): void {
                    $user = auth()->user();

                    $query->when(
                        $user instanceof User,
                        fn ($query) => $query->accessibleBy($user),
                        fn ($query) => $query->whereRaw('1 = 0'),
                    );
                })
            )
            ->columns([
                TextColumn::make('form.name')
                    ->label('Form')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('completion_status')
                    ->label('Status')
                    ->state(fn (FormAssignment $record): string => $record->isCompleted() ? 'Completed' : 'Needs signature')
                    ->badge()
                    ->color(fn (FormAssignment $record): string => $record->isCompleted() ? 'success' : 'warning')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('respondent_label')
                    ->label('Parent / User')
                    ->hidden($onlyMyForms)
                    ->state(fn (FormAssignment $record): string => self::modelLabel($record->respondent)),
                TextColumn::make('subject_label')
                    ->label('Student')
                    ->placeholder('Family / user-level form')
                    ->state(fn (FormAssignment $record): string => self::modelLabel($record->subject)),
                TextColumn::make('version.version')
                    ->label('Version'),
                TextColumn::make('latestSubmittedResponse.signature')
                    ->label('Signature')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestSubmittedResponse.date_signed')
                    ->label('Date Signed')
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
                    ->query(function (Builder $query, array $data): Builder {
                        match ($data['value'] ?? null) {
                            'completed' => FormAssignment::applyCompletedConstraint($query),
                            'pending' => FormAssignment::applyPendingConstraint($query),
                            default => null,
                        };

                        return $query;
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
