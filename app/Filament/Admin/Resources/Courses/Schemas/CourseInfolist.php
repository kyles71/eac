<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Models\Course;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('capacity')
                    ->numeric(),
                TextEntry::make('available_capacity')
                    ->label('Available Spots')
                    ->state(fn (Course $record): int => $record->getAvailableCapacity())
                    ->badge()
                    ->color(fn (Course $record): string => $record->getAvailableCapacity() > 0 ? 'success' : 'danger'),
                TextEntry::make('product.price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? '$'.number_format($state / 100, 2) : 'No product linked')
                    ->placeholder('No product linked'),
                TextEntry::make('start_time')
                    ->dateTime(),
                TextEntry::make('duration')
                    ->numeric(),
                TextEntry::make('teacher_display_name')
                    ->label('Teachers'),
                TextEntry::make('guest_teacher'),
                SpatieMediaLibraryImageEntry::make('course_images')
                    ->label('Images')
                    ->collection('images')
                    ->disk(MediaDisks::public())
                    ->visibility('public')
                    // ->conversion('thumb')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
