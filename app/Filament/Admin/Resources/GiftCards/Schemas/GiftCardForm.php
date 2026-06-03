<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class GiftCardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gift Card')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('gift_card_type_id')
                            ->label('Gift Card Type')
                            ->relationship(
                                name: 'giftCardType',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('name'),
                            )
                            ->nullable()
                            ->preload(),
                        TextInput::make('initial_amount')
                            ->label('Initial Amount')
                            ->moneyCents()
                            ->required(),
                        TextInput::make('remaining_amount')
                            ->label('Remaining Amount')
                            ->moneyCents()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Section::make('Ownership')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('purchased_by_user_id')
                            ->label('Purchased By')
                            ->userRelationship('purchasedBy')
                            ->required(),
                        Select::make('redeemed_by_user_id')
                            ->label('Redeemed By')
                            ->userRelationship('redeemedBy')
                            ->nullable(),
                    ]),
            ]);
    }
}
