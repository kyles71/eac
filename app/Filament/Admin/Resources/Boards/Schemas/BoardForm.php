<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Boards\Schemas;

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemType;
use App\Enums\BoardStageKind;
use App\Enums\BoardTemplate;
use App\Services\Boards\BoardTemplateService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentColor;

final class BoardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Board')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('template')
                        ->options(BoardTemplate::class)
                        ->enum(BoardTemplate::class)
                        ->default(BoardTemplate::GeneralKanban->value)
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $template = $state instanceof BoardTemplate
                                ? $state
                                : BoardTemplate::tryFrom((string) $state);

                            if ($template === null) {
                                return;
                            }

                            $service = app(BoardTemplateService::class);
                            $set('interaction_mode', $service->modeFor($template)->value);
                            $set('allowed_item_types', $service->itemTypesFor($template));
                        }),
                    ...self::detailFields(),
                    Repeater::make('custom_stages')
                        ->label('Starting stages')
                        ->helperText('The first stage receives new ideas and issues submitted from the board toolbar.')
                        ->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(80),
                            Select::make('color')
                                ->options(self::colorOptions())
                                ->default('gray')
                                ->required(),
                            Select::make('kind')
                                ->options(BoardStageKind::class)
                                ->enum(BoardStageKind::class)
                                ->default(BoardStageKind::Active->value)
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->required(fn (Get $get): bool => self::isBlankTemplate($get('template')))
                        ->visible(fn (Get $get): bool => self::isBlankTemplate($get('template')))
                        ->dehydrated(fn (Get $get): bool => self::isBlankTemplate($get('template')))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<int, Section> */
    public static function settingsComponents(bool $disabled = false): array
    {
        return [
            Section::make('Board')
                ->columns(2)
                ->columnSpanFull()
                ->schema(self::detailFields())
                ->disabled($disabled),
        ];
    }

    /** @return array<string, string> */
    public static function colorOptions(): array
    {
        return [
            'gray' => 'Gray',
            'primary' => 'Primary',
            'info' => 'Blue',
            'warning' => 'Amber',
            'success' => 'Green',
            'danger' => 'Red',
        ];
    }

    public static function previewColor(string $color): string
    {
        return (string) (FilamentColor::getColor($color)[500] ?? $color);
    }

    private static function isBlankTemplate(mixed $template): bool
    {
        return $template === BoardTemplate::Blank
            || $template === BoardTemplate::Blank->value;
    }

    /** @return array<int, TextInput|Textarea|Select|CheckboxList> */
    private static function detailFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(120),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(2000)
                ->columnSpanFull(),
            Select::make('interaction_mode')
                ->options(BoardInteractionMode::class)
                ->enum(BoardInteractionMode::class)
                ->default(BoardInteractionMode::Collaborative->value)
                ->helperText('Moderated boards reserve movement and assignments for managers.')
                ->selectablePlaceholder(false)
                ->required(),
            CheckboxList::make('allowed_item_types')
                ->label('Card types')
                ->options(BoardItemType::class)
                ->default([BoardItemType::Task->value])
                ->minItems(1)
                ->required(),
        ];
    }
}
