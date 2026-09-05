<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\ProductQuestionType;
use App\Enums\ProductType;
use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\Student;
use App\Services\ProductStudentAssignmentService;
use App\Services\ProductStudentExclusionService;
use App\Support\MediaDisks;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class ProductForm
{
    public static function configure(
        Schema $schema,
        bool $includeLinkedItem = true,
        ?Costume $costumeContext = null,
    ): Schema {
        return $schema
            ->components([
                ...($includeLinkedItem ? [Section::make('Linked Item')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('productable_type')
                            ->label('Product Type')
                            ->options([
                                Course::class => 'Course',
                                Costume::class => 'Costume',
                                GiftCardType::class => 'Gift Card',
                                Gear::class => 'Gear',
                            ])
                            ->placeholder(ProductType::Standalone->getLabel())
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('productable_id', null);
                                $set('include_productable_images', false);
                                $set('excludedStudents', []);

                                if ($state === Costume::class) {
                                    $set('assignedStudents', []);
                                    $set('requiredCourses', []);
                                    $set('requiredCompetitionTeams', []);
                                    $set('assignedUsers', []);
                                }
                            }),
                        Select::make('productable_id')
                            ->label(fn (Get $get): string => match ($get('productable_type')) {
                                Course::class => 'Linked Course',
                                Costume::class => 'Linked Costume',
                                GiftCardType::class => 'Linked Gift Card Type',
                                Gear::class => 'Linked Gear',
                                default => 'Linked Item',
                            })
                            ->options(fn (Get $get, ?Product $record) => match ($get('productable_type')) {
                                Course::class => self::availableProductableOptions(Course::class, $record),
                                Costume::class => self::availableProductableOptions(Costume::class, $record),
                                GiftCardType::class => self::availableProductableOptions(GiftCardType::class, $record),
                                Gear::class => self::availableProductableOptions(Gear::class, $record),
                                default => [],
                            })
                            ->required(fn (Get $get): bool => $get('productable_type') !== null)
                            ->selectablePlaceholder(false)
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => $get('productable_type') !== null)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (blank($get('name')) && filled($state)) {
                                    $linkedItemName = self::linkedItemName($get('productable_type'), $state);

                                    if (filled($linkedItemName)) {
                                        $set('name', $linkedItemName);
                                    }
                                }

                                if ($get('productable_type') === Costume::class) {
                                    $set('assignedStudents', []);
                                    $set('excludedStudents', []);
                                }

                                if ($get('productable_type') !== GiftCardType::class || $state === null) {
                                    return;
                                }

                                $set('price', null);

                                $giftCardType = GiftCardType::query()->find($state);

                                if ($giftCardType instanceof GiftCardType && ! $giftCardType->allows_custom_amount) {
                                    $set('price', number_format($giftCardType->denomination / 100, 2, '.', ''));
                                }
                            }),
                    ])] : []),
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
                            ->live()
                            ->helperText('Leave blank to make this product available immediately when active.'),
                        DateTimePicker::make('available_until')
                            ->label('Available Until')
                            ->after('available_from')
                            ->required(fn (Get $get): bool => (bool) $get('is_purchase_required'))
                            ->live()
                            ->helperText(fn (Get $get): string => (bool) $get('is_purchase_required')
                                ? 'This is the deadline for the required purchase.'
                                : 'Leave blank to keep this product available indefinitely while active.'),
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
                                    ->required(),
                                DateTimePicker::make('available_until')
                                    ->label('Available Until')
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
                Section::make('Purchase Audience')
                    ->description(fn (Get $get): string => self::isCostumeProduct($get, $costumeContext)
                        ? 'The linked costume course determines eligible households. Specific Students narrow the audience; excluded students are removed.'
                        : ((bool) $get('is_purchase_required')
                            ? 'Course and Competition Team requirements are cumulative. Specific Users and Students qualify directly. With no audience, current-term student households qualify.'
                            : 'Course and Competition Team requirements are cumulative. Specific Users and Students qualify directly. Leave all four empty to make this Product available to everyone.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_purchase_required')
                            ->label('Purchase is required')
                            ->helperText('Creates a purchase expectation with status reporting and optional reminders. It does not block other activity.')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, bool $state): void {
                                if (! $state) {
                                    $set('purchase_reminder_on', null);
                                    $set('excludedStudents', []);
                                }
                            }),
                        DatePicker::make('purchase_reminder_on')
                            ->label('Reminder Date')
                            ->rules(fn (Get $get): array => array_values(array_filter([
                                ($availableFrom = self::formDate($get('available_from'))) === null
                                    ? null
                                    : "after_or_equal:{$availableFrom}",
                                ($availableUntil = self::formDate($get('available_until'))) === null
                                    ? null
                                    : "before_or_equal:{$availableUntil}",
                            ])))
                            ->helperText('The portal action and one-time email begin on this date. Leave blank for reporting without reminders.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_purchase_required')),
                        TextEntry::make('costume_course')
                            ->label('Costume Course')
                            ->state(fn (Get $get): string => self::costumeCourseName($get, $costumeContext))
                            ->visible(fn (Get $get): bool => self::isCostumeProduct($get, $costumeContext)),
                        Select::make('requiredCourses')
                            ->label('Courses')
                            ->relationship(
                                name: 'requiredCourses',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('name'),
                            )
                            ->multiple()
                            ->searchable(['name'])
                            ->preload()
                            ->live()
                            ->helperText('Enrollment in any selected Course satisfies the Course requirement.')
                            ->hidden(fn (Get $get): bool => self::isCostumeProduct($get, $costumeContext)),
                        Select::make('requiredCompetitionTeams')
                            ->label('Competition Teams')
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
                            ->live()
                            ->getOptionLabelFromRecordUsing(
                                fn (CompetitionTeam $record): string => "{$record->season->name}: {$record->name} ({$record->season->status()})",
                            )
                            ->helperText('Membership in any selected Team satisfies the Team requirement. Student households and Team staff qualify; ended seasons do not.')
                            ->hidden(fn (Get $get): bool => self::isCostumeProduct($get, $costumeContext)),
                        Select::make('assignedUsers')
                            ->label('Specific Users')
                            ->multiple()
                            ->userRelationship('assignedUsers')
                            ->live()
                            ->helperText('Selected Users qualify directly, even when they do not meet Course or Competition Team requirements.')
                            ->hidden(fn (Get $get): bool => self::isCostumeProduct($get, $costumeContext)),
                        Select::make('assignedStudents')
                            ->label('Specific Students')
                            ->relationship(
                                name: 'assignedStudents',
                                titleAttribute: 'first_name',
                                modifyQueryUsing: fn (Builder $query, Get $get, ?Product $record): Builder => self::studentOptionsQuery(
                                    $query,
                                    $get,
                                    $record,
                                    $costumeContext,
                                ),
                            )
                            ->multiple()
                            ->searchable(['first_name', 'last_name'])
                            ->preload()
                            ->live()
                            ->getOptionLabelFromRecordUsing(fn (Student $record): string => $record->fullName)
                            ->helperText(fn (Get $get): string => self::isCostumeProduct($get, $costumeContext)
                                ? 'Only students enrolled in the costume course can be selected.'
                                : 'Selected Students\' households qualify directly, even when they do not meet Course or Competition Team requirements.')
                            ->saveRelationshipsUsing(function (?Product $record, array $state): void {
                                if ($record instanceof Product) {
                                    app(ProductStudentAssignmentService::class)->sync($record, $state);
                                }
                            }),
                        Select::make('excludedStudents')
                            ->label('Excluded Students')
                            ->relationship(
                                name: 'excludedStudents',
                                titleAttribute: 'first_name',
                                modifyQueryUsing: fn (Builder $query, Get $get, ?Product $record): Builder => self::excludedStudentOptionsQuery(
                                    $query,
                                    $get,
                                    $record,
                                    $costumeContext,
                                ),
                            )
                            ->multiple()
                            ->searchable(['first_name', 'last_name'])
                            ->preload()
                            ->getOptionLabelFromRecordUsing(fn (Student $record): string => $record->fullName)
                            ->helperText('Excluded students do not create a purchase expectation or qualify their household to see this Product. Other qualifying paths still apply.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_purchase_required'))
                            ->saveRelationshipsUsing(function (?Product $record, array $state): void {
                                if ($record instanceof Product) {
                                    app(ProductStudentExclusionService::class)->sync($record, $state);
                                }
                            })
                            ->columnSpanFull(),
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
                            ->helperText('Show linked course, costume, gear, or gift card images after product images.')
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
     * @param  class-string<Course|Costume|Gear|GiftCardType>  $productableType
     * @return array<int, string>
     */
    private static function availableProductableOptions(string $productableType, ?Product $currentProduct): array
    {
        if ($productableType === Gear::class) {
            return Gear::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

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

    private static function isCostumeProduct(Get $get, ?Costume $costumeContext): bool
    {
        return $costumeContext instanceof Costume || $get('productable_type') === Costume::class;
    }

    /** @param Builder<Student> $query */
    private static function studentOptionsQuery(
        Builder $query,
        Get $get,
        ?Product $record,
        ?Costume $costumeContext,
    ): Builder {
        $costume = $costumeContext;

        if (! $costume instanceof Costume && $get('productable_type') === Costume::class) {
            $costume = Costume::query()->find($get('productable_id'));
        }

        if (! $costume instanceof Costume && $record?->productable instanceof Costume) {
            $costume = $record->productable;
        }

        if (! self::isCostumeProduct($get, $costumeContext)) {
            return $query
                ->orderBy('last_name')
                ->orderBy('first_name');
        }

        if (! $costume instanceof Costume) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('enrollments', fn (Builder $query): Builder => $query
                ->where('course_id', $costume->course_id))
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /** @param Builder<Student> $query */
    private static function excludedStudentOptionsQuery(
        Builder $query,
        Get $get,
        ?Product $record,
        ?Costume $costumeContext,
    ): Builder {
        $costume = $costumeContext;

        if (! $costume instanceof Costume && $get('productable_type') === Costume::class) {
            $costume = Costume::query()->find($get('productable_id'));
        }

        if (! $costume instanceof Costume && $record?->productable instanceof Costume) {
            $costume = $record->productable;
        }

        if ($costume instanceof Costume) {
            return $query
                ->where(function (Builder $query) use ($costume, $record): void {
                    $query->whereHas('enrollments', fn (Builder $query): Builder => $query
                        ->where('course_id', $costume->course_id));

                    if ($record instanceof Product) {
                        $query->orWhereIn('students.id', $record->excludedStudents()->select('students.id'));
                    }
                })
                ->orderBy('last_name')
                ->orderBy('first_name');
        }

        $courseIds = array_map('intval', (array) ($get('requiredCourses') ?? []));
        $teamIds = array_map('intval', (array) ($get('requiredCompetitionTeams') ?? []));
        $assignedStudentIds = array_map('intval', (array) ($get('assignedStudents') ?? []));
        $hasAnyAudience = $courseIds !== []
            || $teamIds !== []
            || $assignedStudentIds !== []
            || filled($get('assignedUsers'));

        return $query
            ->where(function (Builder $query) use (
                $assignedStudentIds,
                $courseIds,
                $hasAnyAudience,
                $record,
                $teamIds,
            ): void {
                $query->whereRaw('1 = 0');

                if ($assignedStudentIds !== []) {
                    $query->orWhereKey($assignedStudentIds);
                }

                if ($courseIds !== []) {
                    $query->orWhereHas('enrollments', fn (Builder $query): Builder => $query
                        ->whereIn('course_id', $courseIds));
                }

                if ($teamIds !== []) {
                    $query->orWhereHas('competitionTeams', fn (Builder $query): Builder => $query
                        ->whereKey($teamIds));
                }

                if (! $hasAnyAudience) {
                    $query->orWhereHas('enrollments.course.academicTerm', fn (Builder $query): Builder => $query
                        ->whereDate('starts_on', '<=', AcademicTerm::comparisonDate())
                        ->whereDate('ends_on', '>=', AcademicTerm::comparisonDate()));
                }

                if ($record instanceof Product) {
                    $query->orWhereIn('students.id', $record->excludedStudents()->select('students.id'));
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    private static function costumeCourseName(Get $get, ?Costume $costumeContext): string
    {
        $costume = $costumeContext;

        if (! $costume instanceof Costume && $get('productable_type') === Costume::class) {
            $costume = Costume::query()->with('course')->find($get('productable_id'));
        }

        return $costume?->course->name ?? 'Select a linked costume first';
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

    private static function linkedItemName(?string $productableType, mixed $productableId): ?string
    {
        $productable = match ($productableType) {
            Course::class => Course::query()->find($productableId),
            Costume::class => Costume::query()->find($productableId),
            GiftCardType::class => GiftCardType::query()->find($productableId),
            Gear::class => Gear::query()->find($productableId),
            default => null,
        };

        $name = $productable?->getAttribute('name');

        return is_string($name) ? $name : null;
    }

    private static function formDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (! is_string($value) || blank($value)) {
            return null;
        }

        return mb_substr($value, 0, 10);
    }
}
