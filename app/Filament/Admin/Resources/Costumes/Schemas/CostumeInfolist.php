<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Schemas;

use App\Enums\ProductAvailabilityStatus;
use App\Models\Costume;
use App\Models\Product;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CostumeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Costume')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('course.name')
                            ->label('Course'),
                        TextEntry::make('course.program_type')
                            ->label('Program Type')
                            ->badge(),
                        TextEntry::make('vendor')
                            ->placeholder('None'),
                        TextEntry::make('vendor_number')
                            ->label('Vendor Number')
                            ->placeholder('None'),
                        TextEntry::make('notes')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),
                Section::make('Product Listing')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('Store Name')
                            ->placeholder('No product listing'),
                        TextEntry::make('product.price')
                            ->label('Price')
                            ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state)
                                ? format_money((int) $state)
                                : null)
                            ->placeholder('No product listing'),
                        TextEntry::make('product_availability')
                            ->label('Availability')
                            ->state(fn (Costume $record): ProductAvailabilityStatus|string => $record->product?->availabilityStatus()
                                ?? 'Not listed')
                            ->badge()
                            ->formatStateUsing(fn (ProductAvailabilityStatus|string $state): string => $state instanceof ProductAvailabilityStatus
                                ? $state->getLabel()
                                : $state)
                            ->color(fn (ProductAvailabilityStatus|string $state): string => $state instanceof ProductAvailabilityStatus
                                ? $state->getColor()
                                : 'gray'),
                        TextEntry::make('product.is_purchase_required')
                            ->label('Purchase Required')
                            ->badge()
                            ->formatStateUsing(fn (?bool $state): string => $state ? 'Yes' : 'No')
                            ->color(fn (?bool $state): string => $state ? 'warning' : 'gray')
                            ->placeholder('No product listing'),
                        TextEntry::make('product.purchase_reminder_on')
                            ->label('Reminder Date')
                            ->date()
                            ->placeholder('None'),
                        TextEntry::make('product.available_until')
                            ->label('Purchase Deadline')
                            ->dateTime()
                            ->placeholder('None'),
                        TextEntry::make('product_student_audience')
                            ->label('Student Audience')
                            ->state(fn (Costume $record): string => self::studentAudience($record->product))
                            ->columnSpanFull(),
                        TextEntry::make('product_student_exclusions')
                            ->label('Excluded Students')
                            ->state(fn (Costume $record): array => $record->product?->excludedStudents()
                                ->orderBy('last_name')
                                ->orderBy('first_name')
                                ->get()
                                ->map(fn ($student): string => $student->fullName)
                                ->all() ?? [])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public'),
                    ]),
            ]);
    }

    private static function studentAudience(?Product $product): string
    {
        if (! $product instanceof Product) {
            return 'No product listing';
        }

        $studentCount = $product->assignedStudents()->count();

        return $studentCount > 0
            ? $studentCount.' assigned '.str('student')->plural($studentCount)
            : 'All course enrollments';
    }
}
