<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\Calendar;
use App\Models\Student;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('nickname')
                            ->placeholder('-'),
                        TextEntry::make('birthdate')
                            ->date(),
                        TextEntry::make('user.full_name')
                            ->label('Parent / User')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),
                Section::make('Tags')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieTagsEntry::make('tags')
                            ->label('Student Tags')
                            ->type(Student::GENERAL_TAG_TYPE),
                        SpatieTagsEntry::make('calendar_audience_tags')
                            ->label('Calendar Audience Tags')
                            ->type(Calendar::AUDIENCE_TAG_TYPE),
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
