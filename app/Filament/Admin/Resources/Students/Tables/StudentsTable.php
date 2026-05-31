<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Tables;

use App\Filament\Actions\SendEmailAction;
use App\Models\Calendar;
use App\Models\Student;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('user.full_name')
                    ->sortable(),
                SpatieTagsColumn::make('tags')
                    ->label('Student Tags')
                    ->type(Student::GENERAL_TAG_TYPE)
                    ->toggleable(),
                SpatieTagsColumn::make('calendar_audience_tags')
                    ->label('Calendar Audience Tags')
                    ->type(Calendar::AUDIENCE_TAG_TYPE)
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
                SendEmailAction::make()
                    ->to(fn ($record) => [$record->user->email]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
