<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Support\MediaDisks;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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
                            ->placeholder('None'),
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
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            // ->conversion('thumb')
                            ->columnSpanFull(),
                    ]),
                Section::make('Substitute Coverage')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('substitute_coverage_status')
                            ->label('Status')
                            ->state(fn (Event $record) => $record->substituteCoverageStatus())
                            ->badge(),
                        TextEntry::make('substituteTeacher.fullName')
                            ->label('Confirmed Substitute')
                            ->placeholder('None'),
                        TextEntry::make('pending_substitute')
                            ->label('Pending Request')
                            ->state(fn (Event $record): ?string => $record->pendingSubstituteRequest()?->teacher?->fullName)
                            ->placeholder('None'),
                        TextEntry::make('substitute_needed_at')
                            ->label('Coverage Needed Since')
                            ->dateTime()
                            ->placeholder('Not marked as needed'),
                        TextEntry::make('release_reason')
                            ->label('Release Request')
                            ->state(fn (Event $record): ?string => $record->currentSubstituteRequest()?->release_reason)
                            ->placeholder('None')
                            ->columnSpanFull(),
                        RepeatableEntry::make('substituteRequests')
                            ->label('Request History')
                            ->table([
                                TableColumn::make('Teacher'),
                                TableColumn::make('Status'),
                                TableColumn::make('Requested By'),
                                TableColumn::make('Requested'),
                                TableColumn::make('Reason / Note'),
                            ])
                            ->schema([
                                TextEntry::make('teacher.fullName')
                                    ->label('Teacher')
                                    ->placeholder('Deleted user'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('requestedBy.fullName')
                                    ->label('Requested By')
                                    ->placeholder('Deleted user'),
                                TextEntry::make('created_at')
                                    ->label('Requested')
                                    ->dateTime(),
                                TextEntry::make('request_summary')
                                    ->label('Reason / Note')
                                    ->state(fn (EventSubstituteRequest $record): ?string => $record->release_reason
                                        ?? $record->response_note
                                        ?? $record->closure_reason
                                        ?? $record->request_reason)
                                    ->placeholder('None'),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Event $record): bool => $record->substitute_needed_at !== null
                        || $record->substituteRequests()->exists()),
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
