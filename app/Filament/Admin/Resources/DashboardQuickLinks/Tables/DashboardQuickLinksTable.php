<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks\Tables;

use App\Enums\DashboardAudience;
use App\Services\DashboardQuickLinkDestinationService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class DashboardQuickLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable(),
                TextColumn::make('audience')
                    ->badge(),
                TextColumn::make('destination')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => app(DashboardQuickLinkDestinationService::class)->labelFor($state)),
                TextColumn::make('external_url')
                    ->label('External URL')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('audience')
                    ->options(DashboardAudience::class),
                TernaryFilter::make('is_active'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
