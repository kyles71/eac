<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Tables;

use App\Enums\CreditGrantStatus;
use App\Filament\Actions\RevokeCreditGrantAction;
use App\Models\CreditGrant;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CreditGrantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.fullName')
                    ->label('Recipient')
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('user.email')
                    ->label('Recipient Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('initial_amount')
                    ->label('Issued')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('net_used')
                    ->state(fn (CreditGrant $record): int => $record->netUsedAmount())
                    ->moneyCents(),
                TextColumn::make('remaining_amount')
                    ->label('Unused')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (CreditGrant $record): CreditGrantStatus => $record->status())
                    ->badge(),
                TextColumn::make('restrictions')
                    ->state(fn (CreditGrant $record): string => $record->restrictionSummary())
                    ->toggleable(),
                TextColumn::make('expires_on')
                    ->date()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('grantedBy.fullName')
                    ->label('Issued By')
                    ->placeholder('Gift card / migration')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('grantedBy.email')
                    ->label('Issuer Email')
                    ->placeholder('Gift card / migration')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Issued At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Recipient')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(CreditGrantStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $today = now('America/New_York')->toDateString();

                        return match ($data['value'] ?? null) {
                            CreditGrantStatus::Active->value => $query
                                ->whereNull('revoked_at')
                                ->where('remaining_amount', '>', 0)
                                ->where(fn (Builder $query): Builder => $query
                                    ->whereNull('expires_on')
                                    ->orWhereDate('expires_on', '>=', $today)),
                            CreditGrantStatus::Depleted->value => $query
                                ->whereNull('revoked_at')
                                ->where('remaining_amount', 0),
                            CreditGrantStatus::Expired->value => $query
                                ->whereNull('revoked_at')
                                ->where('remaining_amount', '>', 0)
                                ->whereDate('expires_on', '<', $today),
                            CreditGrantStatus::Revoked->value => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('restriction')
                    ->options([
                        'unrestricted' => 'Unrestricted',
                        'restricted' => 'Limited Use',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'unrestricted' => $query
                                ->whereNull('restricted_to_product_type')
                                ->where('has_product_restrictions', false),
                            'restricted' => $query->where(function (Builder $query): void {
                                $query
                                    ->whereNotNull('restricted_to_product_type')
                                    ->orWhere('has_product_restrictions', true);
                            }),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    RevokeCreditGrantAction::make(),
                ]),
            ]);
    }
}
