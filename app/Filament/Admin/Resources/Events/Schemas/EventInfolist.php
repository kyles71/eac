<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Models\Event;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('focus')
                            ->label('Focus / Theme (Public)')
                            ->placeholder('None'),
                        TextEntry::make('description')
                            ->label('Public Description')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('details')
                            ->label('Lesson Plan (Staff Only)')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('course.name')
                            ->label('Course')
                            ->placeholder('None'),
                        TextEntry::make('calendar.name')
                            ->label('Calendar')
                            ->placeholder('None'),
                        TextEntry::make('start_time')
                            ->label('Starts At')
                            ->dateTime(),
                        TextEntry::make('end_time')
                            ->label('Ends At')
                            ->dateTime(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            // ->conversion('thumb')
                            ->columnSpanFull(),
                    ]),
                Section::make('Cancellation')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (Event $record): bool => $record->isCancelled())
                    ->schema([
                        TextEntry::make('cancelled_at')
                            ->label('Cancelled At')
                            ->dateTime(),
                        TextEntry::make('cancelledBy.fullName')
                            ->label('Cancelled By')
                            ->placeholder('Unknown'),
                        TextEntry::make('cancellation_reason')
                            ->label('Reason')
                            ->columnSpanFull(),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
