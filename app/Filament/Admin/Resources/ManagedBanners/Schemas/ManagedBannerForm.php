<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\ManagedBannerRenderLocation;
use App\Enums\ManagedBannerTone;
use App\Services\ManagedBannerDestinationService;
use App\Services\ManagedBannerScopeService;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Guava\IconPicker\Forms\Components\IconPicker;

final class ManagedBannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make([
                    'default' => 1,
                    'xl' => 5,
                ])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make('Banner')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('title')
                                        ->required()
                                        ->maxLength(120)
                                        ->live(debounce: 500),
                                    Select::make('tone')
                                        ->options(ManagedBannerTone::class)
                                        ->enum(ManagedBannerTone::class)
                                        ->default(ManagedBannerTone::Info->value)
                                        ->live()
                                        ->selectablePlaceholder(false)
                                        ->required(),
                                    Textarea::make('message')
                                        ->required()
                                        ->maxLength(1000)
                                        ->rows(4)
                                        ->live(debounce: 500)
                                        ->columnSpanFull(),
                                    IconPicker::make('icon')
                                        ->sets(['heroicons'])
                                        ->live()
                                        ->iconsSearchResults()
                                        ->helperText('Leave blank to use the tone default.')
                                        ->columnSpanFull(),
                                ]),
                            Section::make('Placement')
                                ->columns(2)
                                ->schema([
                                    Select::make('render_location')
                                        ->options(ManagedBannerRenderLocation::class)
                                        ->enum(ManagedBannerRenderLocation::class)
                                        ->default(ManagedBannerRenderLocation::ContentStart->value)
                                        ->live()
                                        ->selectablePlaceholder(false)
                                        ->required(),
                                    Select::make('target_scopes')
                                        ->label('Panel pages')
                                        ->options(fn (): array => app(ManagedBannerScopeService::class)->options())
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->helperText('Leave blank to show on every authenticated page in every panel.')
                                        ->columnSpanFull(),
                                ]),
                            Section::make('Call To Action')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('cta_label')
                                        ->label('Label')
                                        ->maxLength(80)
                                        ->live(debounce: 500)
                                        ->required(fn (Get $get): bool => filled($get('cta_url')) || ! self::isExternalDestination($get('cta_destination'))),
                                    Select::make('cta_destination')
                                        ->label('Destination')
                                        ->options(fn (): array => app(ManagedBannerDestinationService::class)->options())
                                        ->default(ManagedBannerDestinationService::EXTERNAL)
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->helperText('Choose an internal panel destination or use an external URL.'),
                                    TextInput::make('cta_url')
                                        ->label('URL')
                                        ->url()
                                        ->maxLength(2048)
                                        ->live(debounce: 500)
                                        ->required(fn (Get $get): bool => filled($get('cta_label')) && self::isExternalDestination($get('cta_destination')))
                                        ->visible(fn (Get $get): bool => self::isExternalDestination($get('cta_destination'))),
                                    Grid::make(2)
                                        ->schema([
                                            Toggle::make('cta_new_tab')
                                                ->label('Open in new tab')
                                                ->default(false)
                                                ->visible(fn (Get $get): bool => filled($get('cta_label'))),
                                        ])
                                        ->columnSpanFull(),
                                ]),
                            Section::make('Visibility')
                                ->columns(2)
                                ->schema([
                                    Toggle::make('is_active')
                                        ->label('Active')
                                        ->default(true)
                                        ->required(),
                                    Toggle::make('is_dismissible')
                                        ->label('User can dismiss')
                                        ->default(false)
                                        ->required(),
                                    DateTimePicker::make('published_at')
                                        ->label('Publish At')
                                        ->helperText('Leave blank to publish immediately.'),
                                    DateTimePicker::make('expires_at')
                                        ->label('Expires At')
                                        ->after('published_at')
                                        ->helperText('Leave blank to keep visible indefinitely.'),
                                    Select::make('audiences')
                                        ->options(DashboardAudience::class)
                                        ->multiple()
                                        ->minItems(1)
                                        ->default([DashboardAudience::Eac->value])
                                        ->required()
                                        ->columnSpanFull(),
                                ]),
                        ])
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 2,
                            ]),
                        Group::make([
                            Section::make('Live Preview')
                                ->schema([
                                    View::make('filament.admin.resources.managed-banners.live-preview')
                                        ->key('managed-banner-live-preview')
                                        ->viewData(fn (Get $get): array => self::previewViewData($get))
                                        ->columnSpanFull(),
                                ]),
                        ])
                            ->extraAttributes(['class' => 'managed-banner-preview-pane'])
                            ->columnSpan([
                                'default' => 1,
                                'xl' => 3,
                            ]),
                    ]),
            ]);
    }

    private static function isExternalDestination(mixed $destination): bool
    {
        return app(ManagedBannerDestinationService::class)->isExternal($destination);
    }

    /**
     * @return array{
     *     tone: ManagedBannerTone,
     *     icon: BackedEnum|string|null,
     *     title: string,
     *     message: string,
     *     renderLocation: ManagedBannerRenderLocation,
     *     ctaLabel: string|null,
     *     ctaUrl: string|null,
     *     ctaNewTab: bool,
     * }
     */
    private static function previewViewData(Get $get): array
    {
        $tone = self::previewTone($get('tone'));
        $renderLocation = self::previewRenderLocation($get('render_location'));
        $destinationService = app(ManagedBannerDestinationService::class);

        return [
            'tone' => $tone,
            'icon' => filled($get('icon')) ? (string) $get('icon') : $tone->defaultIcon(),
            'title' => filled($get('title')) ? (string) $get('title') : 'Untitled banner',
            'message' => filled($get('message')) ? (string) $get('message') : 'Banner message preview will appear here.',
            'renderLocation' => $renderLocation,
            'ctaLabel' => filled($get('cta_label')) ? (string) $get('cta_label') : null,
            'ctaUrl' => self::previewCtaUrl($get, $destinationService),
            'ctaNewTab' => (bool) $get('cta_new_tab'),
        ];
    }

    private static function previewTone(mixed $tone): ManagedBannerTone
    {
        if ($tone instanceof ManagedBannerTone) {
            return $tone;
        }

        return is_string($tone)
            ? ManagedBannerTone::tryFrom($tone) ?? ManagedBannerTone::Info
            : ManagedBannerTone::Info;
    }

    private static function previewRenderLocation(mixed $renderLocation): ManagedBannerRenderLocation
    {
        if ($renderLocation instanceof ManagedBannerRenderLocation) {
            return $renderLocation;
        }

        return is_string($renderLocation)
            ? ManagedBannerRenderLocation::tryFrom($renderLocation) ?? ManagedBannerRenderLocation::ContentStart
            : ManagedBannerRenderLocation::ContentStart;
    }

    private static function previewCtaUrl(Get $get, ManagedBannerDestinationService $destinationService): ?string
    {
        $destination = $get('cta_destination');

        if (is_string($destination) && ! $destinationService->isExternal($destination)) {
            return $destinationService->urlFor($destination);
        }

        return filled($get('cta_url')) ? (string) $get('cta_url') : null;
    }
}
