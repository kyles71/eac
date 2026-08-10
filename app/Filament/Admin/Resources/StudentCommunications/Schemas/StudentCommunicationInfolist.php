<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StudentCommunications\Schemas;

use App\Enums\StudentCommunicationType;
use App\Models\StudentCommunication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentCommunicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student Communication')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('stop_light_color')
                            ->label('Stop Light Color')
                            ->badge()
                            ->visible(fn (StudentCommunication $record): bool => $record->type === StudentCommunicationType::StopLight),
                        TextEntry::make('occurred_at')
                            ->label('Date and Time')
                            ->dateTime(timezone: (string) config('app.display_timezone', config('app.timezone'))),
                        TextEntry::make('event.name')
                            ->label('Event')
                            ->placeholder('No event selected'),
                        TextEntry::make('author.full_name')
                            ->label('Teacher')
                            ->placeholder('Deleted user'),
                        TextEntry::make('student.full_name')
                            ->label('Student'),
                        TextEntry::make('note')
                            ->columnSpanFull(),
                        TextEntry::make('recipient_emails')
                            ->label('Recipients')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                        TextEntry::make('queued_at')
                            ->label('Queued')
                            ->dateTime(),
                    ]),
            ]);
    }
}
