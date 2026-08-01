<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Tables;

use App\Filament\Actions\StudentContactActionGroup;
use App\Models\Student;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

final class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Student')
                    ->state(fn (Student $record): string => $record->fullName)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('nickname')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('birthdate')
                    ->date()
                    ->sortable(),
                TextColumn::make('age')
                    ->state(fn (Student $record): int => $record->age)
                    ->numeric()
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('user.full_name')
                    ->label('Parent / User')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('user.email')
                    ->label('Parent Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                SpatieTagsColumn::make('tags')
                    ->label('Student Tags')
                    ->type(Student::GENERAL_TAG_TYPE)
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
            ->defaultSort('last_name')
            ->recordActions([
                StudentContactActionGroup::make(fn (Student $record): Student => $record),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
