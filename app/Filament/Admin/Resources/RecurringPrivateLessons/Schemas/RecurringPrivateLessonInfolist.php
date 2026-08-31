<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RecurringPrivateLessonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recurring Private Lesson')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('course.name')->label('Lesson / Style'),
                            TextEntry::make('student.full_name')->label('Dancer'),
                            TextEntry::make('user.full_name')->label('Household'),
                            TextEntry::make('course.teacher_display_name')->label('Teachers'),
                            TextEntry::make('lesson_price')->label('Price Per Lesson')->money('USD', divideBy: 100),
                            TextEntry::make('status')->badge(),
                        ]),
                        TextEntry::make('course.description')->label('Description'),
                    ]),
            ]);
    }
}
