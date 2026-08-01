<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners\Tables;

use App\Enums\DashboardAudience;
use App\Enums\ManagedBannerRenderLocation;
use App\Enums\ManagedBannerTone;
use App\Models\ManagedBanner;
use App\Services\ManagedBannerScopeService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ManagedBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('tone')
                    ->badge()
                    ->color(fn (ManagedBannerTone $state): string => $state->getColor()),
                TextColumn::make('status')
                    ->state(fn (ManagedBanner $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Upcoming' => 'info',
                        'Inactive' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('render_location')
                    ->label('Location')
                    ->badge(),
                TextColumn::make('audiences')
                    ->state(fn (ManagedBanner $record): string => implode(', ', $record->audienceLabels()))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('target_scopes')
                    ->label('Pages')
                    ->state(fn (ManagedBanner $record): string => self::scopeSummary($record))
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('is_dismissible')
                    ->label('Dismissible')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('Immediately')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tone')
                    ->options(ManagedBannerTone::class),
                SelectFilter::make('render_location')
                    ->label('Location')
                    ->options(ManagedBannerRenderLocation::class),
                SelectFilter::make('audience')
                    ->options(DashboardAudience::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('audiences', $data['value'])
                        : $query),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'upcoming' => 'Upcoming',
                        'inactive' => 'Inactive',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query
                                ->where('is_active', true)
                                ->where(fn (Builder $query): Builder => $query
                                    ->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now()))
                                ->where(fn (Builder $query): Builder => $query
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now())),
                            'upcoming' => $query->where('is_active', true)->where('published_at', '>', now()),
                            'inactive' => $query->where('is_active', false),
                            'expired' => $query->where('expires_at', '<=', now()),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('is_dismissible')
                    ->label('Dismissible'),
            ])
            ->defaultSort('created_at', 'desc')
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

    private static function scopeSummary(ManagedBanner $record): string
    {
        if (blank($record->target_scopes)) {
            return 'All user panel pages';
        }

        return collect($record->target_scopes)
            ->map(fn (string $scope): string => app(ManagedBannerScopeService::class)->labelFor($scope))
            ->join(', ');
    }
}
