<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\Calendar;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Tags\Tag;

final class StudentForm
{
    public static function configure(Schema $schema, $user_id = null): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name')
                    ->required(),
                Select::make('user_id')
                    ->hidden(fn (): bool => $user_id !== null)
                    ->preload()
                    ->searchableRelationship(
                        name: 'user',
                        searchColumns: ['first_name', 'last_name'],
                        labelFromRecord: fn (User $user): string => $user->fullName,
                        orderBy: ['first_name', 'last_name'],
                    ),
                SpatieTagsInput::make('tags')
                    ->label('Student Tags')
                    ->type(Student::GENERAL_TAG_TYPE)
                    ->columnSpanFull(),
                Select::make('calendar_audience_tag_ids')
                    ->label('Calendar Audience Tags')
                    ->multiple()
                    ->preload()
                    ->searchable()
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
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }
}
