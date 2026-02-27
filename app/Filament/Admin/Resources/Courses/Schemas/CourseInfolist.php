<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Models\Course;
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
                    ->state(fn (Course $record): int => $record->availableCapacity())
                    ->badge()
                    ->color(fn (Course $record): string => $record->availableCapacity() > 0 ? 'success' : 'danger'),
                TextEntry::make('product.price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? '$'.number_format($state / 100, 2) : 'No product linked')
                    ->placeholder('No product linked'),
                TextEntry::make('start_time')
                    ->dateTime(),
                TextEntry::make('duration')
                    ->numeric(),
                TextEntry::make('teacher.full_name')
                    ->label('Teacher\'s Name'),
                TextEntry::make('guest_teacher'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
