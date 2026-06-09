<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages\Tables;

use App\Enums\DashboardAudience;
use App\Models\DashboardMessage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DashboardMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('message')
                    ->limit(80)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('audience')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (DashboardMessage $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Upcoming' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->placeholder('Immediately')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('audience')
                    ->options(DashboardAudience::class),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'upcoming' => 'Upcoming',
                        'expired' => 'Expired',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query
                                ->where(fn (Builder $query): Builder => $query
                                    ->whereNull('published_at')
                                    ->orWhere('published_at', '<=', now()))
                                ->where(fn (Builder $query): Builder => $query
                                    ->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now())),
                            'upcoming' => $query->where('published_at', '>', now()),
                            'expired' => $query->where('expires_at', '<=', now()),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
