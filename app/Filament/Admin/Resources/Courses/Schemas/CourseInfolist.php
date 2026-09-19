<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Models\Course;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Course')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('academic_term')
                            ->label('Academic Term')
                            ->state(fn (Course $record): ?string => $record->academicTerm?->display_name)
                            ->badge()
                            ->color(fn (Course $record): ?string => $record->academicTerm?->semester->getColor()),
                        TextEntry::make('program_type')
                            ->label('Program Type')
                            ->badge(),
                        TextEntry::make('product.price')
                            ->label('Price')
                            ->moneyCents('No product linked'),
                        TextEntry::make('capacity')
                            ->numeric(),
                        TextEntry::make('available_capacity')
                            ->label('Available Spots')
                            ->state(fn (Course $record): int => $record->getAvailableCapacity())
                            ->badge()
                            ->color(fn (Course $record): string => $record->getAvailableCapacity() > 0 ? 'success' : 'danger'),
                        TextEntry::make('first_meeting_starts_at')
                            ->label('Starts At')
                            ->state(fn (Course $record): mixed => $record->firstMeetingStartsAt())
                            ->dateTime(),
                        TextEntry::make('scheduled_duration')
                            ->label('Duration (minutes)')
                            ->state(fn (Course $record): ?int => $record->scheduledDurationMinutes())
                            ->numeric(),
                        TextEntry::make('teacher_display_name')
                            ->label('Teachers'),
                        TextEntry::make('guest_teacher')
                            ->label('Guest Teacher')
                            ->placeholder('None'),
                        SpatieTagsEntry::make('tags')
                            ->label('Course Tags')
                            ->type(Course::GENERAL_TAG_TYPE)
                            ->columnSpanFull(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('course_images')
                            ->label('Images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            // ->conversion('thumb')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
