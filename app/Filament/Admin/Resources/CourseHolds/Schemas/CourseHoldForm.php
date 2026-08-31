<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds\Schemas;

use App\Models\Course;
use App\Rules\FutureDisplayDateTime;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CourseHoldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hold Details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('user_id')
                            ->label('Family / User')
                            ->userRelationship('user')
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        DateTimePicker::make('expires_at')
                            ->label('Expires At')
                            ->rules([new FutureDisplayDateTime])
                            ->required(),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Held Classes')
                    ->description('Prices are locked when each class is added to the hold.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('lines')
                            ->label('Classes')
                            ->schema([
                                Select::make('course_id')
                                    ->label('Class')
                                    ->options(fn (): array => Course::query()
                                        ->whereHas('product')
                                        ->notConcluded()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->distinct()
                                    ->required(),
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->required()
                            ->visibleOn('create'),
                        Repeater::make('additional_lines')
                            ->label('Add More Seats')
                            ->helperText('Optional. Existing held seats are unchanged.')
                            ->schema([
                                Select::make('course_id')
                                    ->label('Class')
                                    ->options(fn (): array => Course::query()
                                        ->whereHas('product')
                                        ->notConcluded()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->distinct()
                                    ->required(),
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->visibleOn('edit'),
                    ]),
            ]);
    }
}
