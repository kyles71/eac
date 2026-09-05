<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Tables;

use App\Enums\CourseProgramType;
use App\Filament\Actions\DeleteProductableAction;
use App\Filament\Actions\DeleteProductableBulkAction;
use App\Filament\Actions\ManageProductListingAction;
use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Models\Costume;
use App\Support\MediaDisks;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CostumesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->collection('images')
                    ->disk(MediaDisks::public())
                    ->visibility('public')
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('course.name')
                    ->label('Course')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('course.program_type')
                    ->label('Program Type')
                    ->badge(),
                TextColumn::make('vendor')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('vendor_number')
                    ->label('Vendor Number')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('product.name')
                    ->label('Product Listing')
                    ->placeholder('No product'),
            ])
            ->filters([
                SelectFilter::make('program_type')
                    ->label('Program Type')
                    ->options(CourseProgramType::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('course', fn (Builder $query): Builder => $query->where('program_type', $data['value']))
                        : $query),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ManageProductListingAction::make(),
                    DeleteProductableAction::make(),
                ]),
            ])
            ->recordUrl(fn (Costume $record): string => CostumeResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteProductableBulkAction::make(),
                ]),
            ]);
    }
}
