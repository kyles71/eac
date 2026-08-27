<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\FulfillmentWorkflow;
use App\Enums\ProductQuestionType;
use App\Enums\ProductType;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Support\MediaDisks;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Linked Item')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('productable_type')
                            ->label('Product Type')
                            ->options([
                                Course::class => 'Course',
                                GiftCardType::class => 'Gift Card',
                                Gear::class => 'Gear',
                            ])
                            ->placeholder(ProductType::Standalone->getLabel())
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('productable_id', null);
                                $set('include_productable_images', false);
                                $set(
                                    'fulfillment_workflow',
                                    in_array($state, [Course::class, GiftCardType::class], true)
                                        ? FulfillmentWorkflow::Automatic->value
                                        : FulfillmentWorkflow::Manual->value,
                                );
                            }),
                        Select::make('productable_id')
                            ->label(fn (Get $get): string => match ($get('productable_type')) {
                                Course::class => 'Linked Course',
                                GiftCardType::class => 'Linked Gift Card Type',
                                Gear::class => 'Linked Gear',
                                default => 'Linked Item',
                            })
                            ->options(fn (Get $get, ?Product $record) => match ($get('productable_type')) {
                                Course::class => self::availableProductableOptions(Course::class, $record),
                                GiftCardType::class => self::availableProductableOptions(GiftCardType::class, $record),
                                Gear::class => self::availableProductableOptions(Gear::class, $record),
                                default => [],
                            })
                            ->required(fn (Get $get): bool => $get('productable_type') !== null)
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('productable_type') !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($get('productable_type') !== GiftCardType::class || $state === null) {
                                    return;
                                }

                                $set('price', null);

                                $giftCardType = GiftCardType::query()->find($state);

                                if ($giftCardType instanceof GiftCardType && ! $giftCardType->allows_custom_amount) {
                                    $set('price', number_format($giftCardType->denomination / 100, 2, '.', ''));
                                }
                            }),
                    ]),
                Section::make('Fulfillment')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('fulfillment_workflow')
                            ->label('Fulfillment Workflow')
                            ->options(fn (Get $get): array => in_array($get('productable_type'), [Course::class, GiftCardType::class], true)
                                ? [FulfillmentWorkflow::Automatic->value => FulfillmentWorkflow::Automatic->getLabel()]
                                : FulfillmentWorkflow::configurableOptions())
                            ->searchable(false)
                            ->default(FulfillmentWorkflow::Manual->value)
                            ->disabled(fn (Get $get): bool => in_array($get('productable_type'), [Course::class, GiftCardType::class], true))
                            ->dehydrated()
                            ->preload()
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText(fn (Get $get): string => in_array($get('productable_type'), [Course::class, GiftCardType::class], true)
                                ? 'The linked item fulfills purchases automatically.'
                                : 'Manual items are completed by staff. Scheduled-event items are completed by creating or attaching an event. The choice is copied to future order items.'),
                    ]),
                Section::make('Store Details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Store Name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Draft products never appear in the store, even when scheduled or granted early access.')
                            ->default(true),
                        TextInput::make('price')
                            ->label('Price')
                            ->moneyCents()
                            ->required(fn (Get $get): bool => self::requiresFixedPrice($get))
                            ->visible(fn (Get $get): bool => self::requiresFixedPrice($get)),
                        Text::make(fn (Get $get): string => self::customerEnteredPricingSummary($get))
                            ->visible(fn (Get $get): bool => self::usesCustomerEnteredPricing($get)),
                        Textarea::make('description')
                            ->label('Store Description')
                            ->columnSpanFull(),
                    ]),
                Section::make('Availability')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        DateTimePicker::make('available_from')
                            ->label('Available From')
                            ->timezone(self::displayTimezone())
                            ->helperText('Leave blank to make this product available immediately when active.'),
                        DateTimePicker::make('available_until')
                            ->label('Available Until')
                            ->timezone(self::displayTimezone())
                            ->after('available_from')
                            ->helperText('Leave blank to keep this product available indefinitely while active.'),
                        Repeater::make('earlyAccessWindows')
                            ->label('Early Access Windows')
                            ->relationship()
                            ->table([
                                TableColumn::make('Available From'),
                                TableColumn::make('Available Until'),
                                TableColumn::make('Audiences'),
                                TableColumn::make('Users'),
                            ])
                            ->compact()
                            ->schema([
                                DateTimePicker::make('available_from')
                                    ->label('Available From')
                                    ->timezone(self::displayTimezone())
                                    ->required(),
                                DateTimePicker::make('available_until')
                                    ->label('Available Until')
                                    ->timezone(self::displayTimezone())
                                    ->after('available_from'),
                                Select::make('audiences')
                                    ->label('Audiences')
                                    ->options(DashboardAudience::class)
                                    ->multiple()
                                    ->required(fn (Get $get): bool => blank($get('users')) && blank($get('audiences')))
                                    ->dehydrateStateUsing(fn (?array $state): array => array_values($state ?? [])),
                                Select::make('users')
                                    ->label('Users')
                                    ->multiple()
                                    ->userRelationship('users')
                                    ->required(fn (Get $get): bool => blank($get('audiences')) && blank($get('users'))),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add early access window')
                            ->reorderable(false)
                            ->collapsible()
                            ->columnSpanFull(),
                    ]),
                Section::make('Purchase Eligibility')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('requiredCourses')
                            ->label('Requires Enrollment In At Least One Of')
                            ->relationship(
                                name: 'requiredCourses',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('name'),
                            )
                            ->multiple()
                            ->searchable(['name'])
                            ->preload()
                            ->helperText('Leave empty for no course requirement. When teams are also selected, both requirements must be met.'),
                        Select::make('requiredCompetitionTeams')
                            ->label('Requires Membership In At Least One Of')
                            ->relationship(
                                name: 'requiredCompetitionTeams',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query, ?Product $record): Builder {
                                    return $query
                                        ->with('season')
                                        ->where(function (Builder $query) use ($record): void {
                                            $query->whereHas(
                                                'season',
                                                fn (Builder $query): Builder => CompetitionSeason::constrainToNotEnded($query),
                                            );

                                            if ($record !== null) {
                                                $query->orWhereIn(
                                                    'competition_teams.id',
                                                    $record->requiredCompetitionTeams()->select('competition_teams.id'),
                                                );
                                            }
                                        })
                                        ->orderBy('name');
                                },
                            )
                            ->multiple()
                            ->searchable(['name'])
                            ->preload()
                            ->getOptionLabelFromRecordUsing(
                                fn (CompetitionTeam $record): string => "{$record->season->name}: {$record->name} ({$record->season->status()})",
                            )
                            ->helperText('Leave empty for no team requirement. Student households and assigned team staff qualify; ended seasons do not. When courses are also selected, both requirements must be met.'),
                    ]),
                Section::make('Purchaser Questions & Notifications')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('send_purchase_notification')
                            ->label('Email EAC when this product is purchased')
                            ->default(false),
                        Repeater::make('questions')
                            ->label('Purchaser Questions')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->schema([
                                Textarea::make('question')
                                    ->label('Question')
                                    ->rows(2)
                                    ->maxLength(1000)
                                    ->required()
                                    ->columnSpanFull(),
                                Select::make('type')
                                    ->options(ProductQuestionType::class)
                                    ->default(ProductQuestionType::Text->value)
                                    ->searchable(false)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live(),
                                TextInput::make('max_length')
                                    ->label('Maximum Length')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(65535)
                                    ->default(255)
                                    ->required(fn (Get $get): bool => self::questionType($get('type')) === ProductQuestionType::Text)
                                    ->visible(fn (Get $get): bool => self::questionType($get('type')) === ProductQuestionType::Text),
                                Toggle::make('allows_other')
                                    ->label('Include an Other option')
                                    ->inline(false)
                                    ->visible(fn (Get $get): bool => self::questionType($get('type')) === ProductQuestionType::Select),
                                Toggle::make('is_required')
                                    ->label('Required')
                                    ->inline(false)
                                    ->default(false),
                                Repeater::make('options')
                                    ->label('Options')
                                    ->compact()
                                    ->table([
                                        TableColumn::make('Option'),
                                    ])
                                    ->simple(
                                        TextInput::make('option')
                                            ->maxLength(255)
                                            ->distinct()
                                            ->rule(static function (): Closure {
                                                return static function (string $attribute, mixed $value, Closure $fail): void {
                                                    if (mb_strtolower(mb_trim((string) $value)) === 'other') {
                                                        $fail('Other is reserved. Enable the separate Other option instead.');
                                                    }
                                                };
                                            })
                                            ->required(),
                                    )
                                    ->minItems(1)
                                    ->required(fn (Get $get): bool => self::questionType($get('type')) === ProductQuestionType::Select)
                                    ->visible(fn (Get $get): bool => self::questionType($get('type')) === ProductQuestionType::Select)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add purchaser question')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['question'] ?? null)
                                ? (string) $state['question']
                                : null)
                            ->mutateRelationshipDataBeforeCreateUsing(
                                fn (array $data): array => self::normalizeQuestionData($data),
                            )
                            ->mutateRelationshipDataBeforeSaveUsing(
                                fn (array $data): array => self::normalizeQuestionData($data),
                            )
                            ->columnSpanFull(),
                    ]),
                Section::make('Media')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('include_productable_images')
                            ->label('Include linked item images')
                            ->helperText('Show linked course, gear, or gift card images after product images.')
                            ->default(false)
                            ->visible(fn (Get $get): bool => $get('productable_type') !== null && $get('productable_id') !== null)
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->reorderable()
                            ->image(),
                        SpatieMediaLibraryFileUpload::make('documents')
                            ->collection('documents')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ]),
                        SpatieMediaLibraryFileUpload::make('videos')
                            ->collection('videos')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->allowVideo(),
                    ]),
            ]);
    }

    /**
     * @param  class-string<Course|Gear|GiftCardType>  $productableType
     * @return array<int, string>
     */
    private static function availableProductableOptions(string $productableType, ?Product $currentProduct): array
    {
        return $productableType::query()
            ->where(function (Builder $query) use ($productableType, $currentProduct): void {
                $query->whereDoesntHave('product');

                if ($currentProduct?->productable_type === $productableType) {
                    $query->orWhere('id', $currentProduct->productable_id);
                }
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    private static function requiresFixedPrice(Get $get): bool
    {
        return ! self::usesCustomerEnteredPricing($get);
    }

    private static function usesCustomerEnteredPricing(Get $get): bool
    {
        $giftCardType = self::selectedGiftCardType($get);

        return $giftCardType instanceof GiftCardType && $giftCardType->allows_custom_amount;
    }

    private static function selectedGiftCardType(Get $get): ?GiftCardType
    {
        if ($get('productable_type') !== GiftCardType::class || blank($get('productable_id'))) {
            return null;
        }

        return GiftCardType::query()->find($get('productable_id'));
    }

    private static function customerEnteredPricingSummary(Get $get): string
    {
        $giftCardType = self::selectedGiftCardType($get);

        if (! $giftCardType instanceof GiftCardType) {
            return '';
        }

        return 'Customer enters amount. Minimum '.$giftCardType->formattedMinimumCustomAmount().'; suggested amount '.$giftCardType->formattedDenomination().'.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeQuestionData(array $data): array
    {
        $type = $data['type'] ?? null;
        $type = $type instanceof ProductQuestionType
            ? $type
            : ProductQuestionType::tryFrom((string) $type);

        if ($type === ProductQuestionType::Text) {
            $data['options'] = null;
            $data['allows_other'] = false;

            return $data;
        }

        $data['max_length'] = null;
        $data['options'] = collect($data['options'] ?? [])
            ->map(fn (mixed $option): string => mb_trim((string) (is_array($option) ? ($option['option'] ?? '') : $option)))
            ->filter()
            ->unique(fn (string $option): string => mb_strtolower($option))
            ->values()
            ->all();

        return $data;
    }

    private static function questionType(mixed $type): ?ProductQuestionType
    {
        return $type instanceof ProductQuestionType
            ? $type
            : ProductQuestionType::tryFrom((string) $type);
    }
}
