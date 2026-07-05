<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Actions\ManageUserAccessAction;
use App\Filament\Actions\SendEmailAction;
use App\Support\MediaDisks;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum([
                'creditGrants as available_store_credit' => fn (Builder $query): Builder => $query
                    ->whereNull('revoked_at')
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('expires_on')
                            ->orWhereDate('expires_on', '>=', now('America/New_York')->toDateString());
                    })
                    ->where('remaining_amount', '>', 0)
                    ->whereNull('restricted_to_product_type')
                    ->where('has_product_restrictions', false),
            ], 'remaining_amount'))
            ->columns([
                SpatieMediaLibraryImageColumn::make('avatar')
                    ->collection('avatars')
                    ->disk(MediaDisks::private())
                    ->visibility('private')
                    // ->conversion('thumb')
                    ->circular(),
                TextColumn::make('first_name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('last_name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('available_store_credit')
                    ->label('Store Credit')
                    ->moneyCents()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ManageUserAccessAction::make(),
                SendEmailAction::make()
                    ->to(fn ($record) => [$record->email]),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
