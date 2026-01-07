<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\ScheduleFrequency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema, $course_id = null): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('course_id')
                    ->hidden(fn () => $course_id !== null)
                    ->relationship('course', 'name'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('focus'),
                Textarea::make('details')
                    ->label('Lesson Plan')
                    ->columnSpanFull(),
                DateTimePicker::make('start_time'),
                DateTimePicker::make('end_time'),
                Select::make('repeat_frequency')
                    ->live()
                    ->visible(fn (string $operation) => $operation === 'create')
                    ->enum(ScheduleFrequency::class)
                    ->options(ScheduleFrequency::class),
                DatePicker::make('repeat_through')
                    ->visible(fn (Get $get): bool => !! $get('repeat_frequency')),
            ]);
    }
}
