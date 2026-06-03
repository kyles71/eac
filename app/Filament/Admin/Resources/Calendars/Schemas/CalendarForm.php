<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Calendars\Schemas;

use App\Models\Calendar;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Tags\Tag;

final class CalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Calendar')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        ColorPicker::make('background_color')
                            ->label('Background Color')
                            ->regex('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})\b$/'),
                        Select::make('audience_tag_ids')
                            ->label('Audience Tags')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => self::audienceTagOptions())
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Audience Tag')
                                    ->required(),
                            ])
                            ->createOptionUsing(fn (array $data): int => Tag::findOrCreate($data['name'], Calendar::AUDIENCE_TAG_TYPE)->id)
                            ->loadStateFromRelationshipsUsing(function (Select $component, ?Calendar $record): void {
                                $component->state($record?->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)
                                    ->pluck('id')
                                    ->map(fn (int $id): string => (string) $id)
                                    ->all() ?? []);
                            })
                            ->saveRelationshipsUsing(function (?Calendar $record, array $state): void {
                                $tagIds = Tag::query()
                                    ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                                    ->whereIn('id', $state)
                                    ->pluck('id')
                                    ->all();

                                $record?->syncTagIds($tagIds, Calendar::AUDIENCE_TAG_TYPE);
                            })
                            ->dehydrated(false)
                            ->hidden(fn (?Calendar $record): bool => in_array($record?->slug, Calendar::SYSTEM_SLUGS, true))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function audienceTagOptions(): array
    {
        return Tag::query()
            ->where('type', Calendar::AUDIENCE_TAG_TYPE)
            ->orderBy('order_column')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [$tag->id => $tag->name])
            ->all();
    }
}
