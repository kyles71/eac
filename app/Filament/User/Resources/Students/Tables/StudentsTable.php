<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\Students\Tables;

use App\Models\Student;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nickname')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('birthdate')
                    ->date()
                    ->sortable(),
                TextColumn::make('enrollments_count')
                    ->label('Classes')
                    ->counts('enrollments')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Student $record): bool => $record->enrollments()->doesntExist())
                    ->successNotification(
                        Notification::make()
                            ->title('Student deleted')
                            ->success(),
                    ),
            ])
            ->defaultSort('first_name')
            ->emptyStateHeading('No students')
            ->emptyStateDescription('Add a student to make class assignments faster.')
            ->emptyStateIcon('heroicon-o-users');
    }
}
