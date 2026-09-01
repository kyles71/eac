<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\ProductAvailabilityStatus;
use App\Enums\ProductQuestionType;
use App\Enums\ProductType;
use App\Models\CompetitionTeam;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Store Details')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('price')
                            ->formatStateUsing(fn (mixed $state, Product $record): ?string => self::formatPriceState($state, $record))
                            ->placeholder('Missing price'),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                        TextEntry::make('availability_status')
                            ->label('Availability')
                            ->state(fn (Product $record): ProductAvailabilityStatus => $record->availabilityStatus())
                            ->badge()
                            ->formatStateUsing(fn (ProductAvailabilityStatus $state): string => $state->getLabel())
                            ->color(fn (ProductAvailabilityStatus $state): string => $state->getColor()),
                        TextEntry::make('description')
                            ->columnSpanFull()
                            ->placeholder('None'),
                    ]),
                Section::make('Availability')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('available_from')
                            ->dateTime()
                            ->placeholder('Immediately'),
                        TextEntry::make('available_until')
                            ->dateTime()
                            ->placeholder('Never'),
                        RepeatableEntry::make('earlyAccessWindows')
                            ->label('Early Access Windows')
                            ->schema([
                                TextEntry::make('available_from')
                                    ->dateTime(),
                                TextEntry::make('available_until')
                                    ->dateTime()
                                    ->placeholder('No window end'),
                                TextEntry::make('audiences')
                                    ->state(fn (ProductEarlyAccessWindow $record): string => self::formatAudienceValues($record->audiences ?? []))
                                    ->placeholder('None'),
                                TextEntry::make('users')
                                    ->state(fn (ProductEarlyAccessWindow $record): string => self::formatWindowUsers($record))
                                    ->placeholder('None'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Purchase Audience')
                    ->description('Customers must meet each configured group requirement. Specific Users qualify as overrides. An empty audience is available to everyone while its store schedule is open.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('required_courses')
                            ->label('Courses')
                            ->state(fn (Product $record): array => $record->requiredCourses()
                                ->orderBy('name')
                                ->pluck('name')
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('None'),
                        TextEntry::make('required_competition_teams')
                            ->label('Competition Teams')
                            ->state(fn (Product $record): array => $record->requiredCompetitionTeams()
                                ->with('season')
                                ->get()
                                ->sortBy(fn (CompetitionTeam $team): string => $team->season->name.' '.$team->name)
                                ->map(fn (CompetitionTeam $team): string => "{$team->season->name}: {$team->name} ({$team->season->status()})")
                                ->values()
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('None'),
                        TextEntry::make('assigned_users')
                            ->label('Specific Users')
                            ->state(fn (Product $record): array => $record->assignedUsers()
                                ->orderBy('first_name')
                                ->orderBy('last_name')
                                ->get()
                                ->map(fn (User $user): string => filled($user->email)
                                    ? "{$user->fullName} ({$user->email})"
                                    : $user->fullName)
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('None'),
                    ]),
                Section::make('Linked Item')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('productable_type')
                            ->label('Product Type')
                            ->formatStateUsing(fn (?string $state): string => ProductType::labelForProductableType($state)),
                        TextEntry::make('productable.name')
                            ->label('Linked To')
                            ->visible(fn (Product $record): bool => $record->productable !== null),
                        TextEntry::make('fulfillment_workflow')
                            ->label('Fulfillment Workflow')
                            ->state(fn (Product $record) => $record->fulfillmentWorkflow())
                            ->badge(),
                        TextEntry::make('include_productable_images')
                            ->label('Includes Linked Images')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->visible(fn (Product $record): bool => $record->productable !== null),
                        TextEntry::make('order_items_count')
                            ->label('Times Ordered')
                            ->state(fn (Product $record): int => $record->orderItems()->count()),
                    ]),
                Section::make('Purchaser Questions & Notifications')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('send_purchase_notification')
                            ->label('Emails Staff After Purchase')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('question_count')
                            ->label('Questions')
                            ->state(fn (Product $record): int => $record->questions()->count()),
                        RepeatableEntry::make('questions')
                            ->schema([
                                TextEntry::make('question'),
                                TextEntry::make('type')
                                    ->badge(),
                                TextEntry::make('is_required')
                                    ->label('Required')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                                TextEntry::make('configuration')
                                    ->state(fn (ProductQuestion $record): string => match ($record->type) {
                                        ProductQuestionType::Text => "Maximum length: {$record->max_length}",
                                        ProductQuestionType::Select => collect($record->options)
                                            ->when($record->allows_other, fn ($options) => $options->push('Other'))
                                            ->join(', '),
                                    }),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public'),
                        // ->conversion('thumb'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }

    private static function formatPriceState(mixed $state, Product $record): ?string
    {
        if ($record->usesCustomerEnteredPricing()) {
            return 'Customer-entered';
        }

        return is_numeric($state) ? format_money((int) $state) : null;
    }

    /**
     * @param  array<int, string>  $audienceValues
     */
    private static function formatAudienceValues(array $audienceValues): string
    {
        return collect($audienceValues)
            ->map(fn (string $audience): ?string => DashboardAudience::tryFrom($audience)?->getLabel())
            ->filter()
            ->join(', ');
    }

    private static function formatWindowUsers(ProductEarlyAccessWindow $window): string
    {
        return $window->users()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user): string => $user->fullName)
            ->join(', ');
    }
}
