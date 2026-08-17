<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Tables;

use App\Models\RecurringPrivateLesson;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class RecurringPrivateLessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('scheduledCharges.event'))
            ->columns([
                TextColumn::make('course.name')
                    ->label('Lesson')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student.full_name')
                    ->label('Dancer')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('user.full_name')
                    ->label('Household')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('course.teacher_display_name')
                    ->label('Teacher'),
                TextColumn::make('lesson_price')
                    ->label('Per Lesson')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('next_unbilled')
                    ->label('Next Unbilled')
                    ->state(fn (RecurringPrivateLesson $record) => $record->nextUnbilledLessonAt())
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('None'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }
}
