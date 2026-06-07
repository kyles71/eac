<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\Calendar;
use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Tags\Tag;

final class StudentForm
{
    public static function configure(Schema $schema, $user_id = null): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('first_name')
                            ->required(),
                        TextInput::make('last_name')
                            ->required(),
                        TextInput::make('nickname'),
                        DatePicker::make('birthdate')
                            ->required()
                            ->maxDate(today()),
                        Select::make('user_id')
                            ->label('Parent / User')
                            ->hidden(fn (): bool => $user_id !== null)
                            ->userRelationship(),
                    ]),
                Section::make('Tags')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieTagsInput::make('tags')
                            ->label('Student Tags')
                            ->type(Student::GENERAL_TAG_TYPE),
                        Select::make('calendar_audience_tag_ids')
                            ->label('Calendar Audience Tags')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => Tag::query()
                                ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                                ->orderBy('order_column')
                                ->orderBy('id')
                                ->get()
                                ->filter(fn (Tag $tag): bool => Calendar::isStudentAssignableAudienceTag($tag))
                                ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->name])
                                ->all())
                            ->loadStateFromRelationshipsUsing(function (Select $component, ?Student $record): void {
                                $component->state($record?->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)
                                    ->pluck('id')
                                    ->map(fn (int $id): string => (string) $id)
                                    ->all() ?? []);
                            })
                            ->saveRelationshipsUsing(function (?Student $record, array $state): void {
                                $tagIds = Tag::query()
                                    ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                                    ->whereIn('id', $state)
                                    ->get()
                                    ->filter(fn (Tag $tag): bool => Calendar::isStudentAssignableAudienceTag($tag))
                                    ->pluck('id')
                                    ->all();

                                $record?->syncTagIds($tagIds, Calendar::AUDIENCE_TAG_TYPE);
                            })
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
