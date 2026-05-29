<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\Product;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryImageEntry::make('images')
                    ->collection('images')
                    ->disk(MediaDisks::public())
                    ->visibility('public'),
                // ->conversion('thumb'),
                TextEntry::make('name'),
                TextEntry::make('description'),
                TextEntry::make('price')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextEntry::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
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
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }
}
