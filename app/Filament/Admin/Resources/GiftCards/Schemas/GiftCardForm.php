<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Schemas;

use App\Support\GiftCards\GiftCardCodeGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
                            ->default(fn (): string => app(GiftCardCodeGenerator::class)->generate())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->suffixAction(
                                Action::make('generateCode')
                                    ->label('Generate random code')
                                    ->icon(Heroicon::ArrowPath)
                                    ->iconButton()
                                    ->action(fn (Set $set) => $set('code', app(GiftCardCodeGenerator::class)->generate())),
                            ),
                        Select::make('gift_card_type_id')
                            ->label('Gift Card Type')
                            ->relationship(
                                name: 'giftCardType',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('name'),
                            )
                            ->nullable()
                            ->helperText('Optional. Leave blank for an unrestricted one-off gift card.')
                            ->preload(),
                        TextInput::make('initial_amount')
                            ->label('Amount')
                            ->moneyCents(0.01)
                            ->required(),
                    ]),
                Section::make('Ownership')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('purchased_by_user_id')
                            ->label('Purchased By')
                            ->userRelationship('purchasedBy')
                            ->required(),
                    ]),
            ]);
    }
}
