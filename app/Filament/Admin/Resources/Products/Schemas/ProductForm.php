<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\ProductQuestionType;
use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\Course;
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
                Section::make('Store Details')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Store Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('price')
                            ->label('Price')
                            ->moneyCents()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Draft products never appear in the store, even when scheduled or granted early access.')
                            ->default(true),
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
                Section::make('Linked Item')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('productable_type')
                            ->label('Product Type')
                            ->options([
                                Course::class => 'Course',
                                GiftCardType::class => 'Gift Card',
                                Costume::class => 'Costume',
                            ])
                            ->placeholder(ProductType::Standalone->getLabel())
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('productable_id', null);
                                $set('include_productable_images', false);
                            }),
                        Select::make('productable_id')
                            ->label(fn (Get $get): string => match ($get('productable_type')) {
                                Course::class => 'Linked Course',
                                GiftCardType::class => 'Linked Gift Card Type',
                                Costume::class => 'Linked Costume',
                                default => 'Linked Item',
                            })
                            ->options(fn (Get $get, ?Product $record) => match ($get('productable_type')) {
                                Course::class => self::availableProductableOptions(Course::class, $record),
                                GiftCardType::class => self::availableProductableOptions(GiftCardType::class, $record),
                                Costume::class => self::availableProductableOptions(Costume::class, $record),
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

                                $denomination = GiftCardType::query()->find($state)?->denomination;

                                if ($denomination !== null) {
                                    $set('price', number_format($denomination / 100, 2, '.', ''));
                                }
                            }),
                        Select::make('requires_course_id')
                            ->label('Requires Enrollment In')
                            ->helperText('Only available to users already enrolled in this course.')
                            ->relationship(
                                name: 'requiresCourse',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->nullable()
                            ->preload()
                            ->visible(fn (Get $get): bool => in_array($get('productable_type'), [Course::class, Costume::class], true)),
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
                            ->helperText('Show linked course, costume, or gift card images after product images.')
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
     * @param  class-string<Costume|Course|GiftCardType>  $productableType
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
