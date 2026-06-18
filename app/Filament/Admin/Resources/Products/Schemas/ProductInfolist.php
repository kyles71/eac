<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\DashboardAudience;
use App\Enums\ProductAvailabilityStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
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
                            ->moneyCents(),
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
                    ->columnSpanFull()
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
                        TextEntry::make('include_productable_images')
                            ->label('Includes Linked Images')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->visible(fn (Product $record): bool => $record->productable !== null),
                        TextEntry::make('requiresCourse.name')
                            ->label('Requires Enrollment In')
                            ->placeholder('None'),
                        TextEntry::make('order_items_count')
                            ->label('Times Ordered')
                            ->state(fn (Product $record): int => $record->orderItems()->count()),
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
