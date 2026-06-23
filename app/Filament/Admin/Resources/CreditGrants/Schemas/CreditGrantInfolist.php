<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Schemas;

use App\Enums\CreditGrantStatus;
use App\Models\CreditGrant;
use App\Models\GiftCard;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CreditGrantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Store Credit Grant')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('user.fullName')
                            ->label('Recipient'),
                        TextEntry::make('initial_amount')
                            ->label('Issued')
                            ->moneyCents(),
                        TextEntry::make('net_used')
                            ->state(fn (CreditGrant $record): int => $record->netUsedAmount())
                            ->moneyCents(),
                        TextEntry::make('remaining_amount')
                            ->label('Unused')
                            ->moneyCents(),
                        TextEntry::make('status')
                            ->state(fn (CreditGrant $record): CreditGrantStatus => $record->status())
                            ->badge(),
                        TextEntry::make('expires_on')
                            ->date()
                            ->placeholder('Never'),
                        TextEntry::make('description')
                            ->columnSpanFull(),
                        TextEntry::make('restrictions')
                            ->state(fn (CreditGrant $record): string => $record->restrictionSummary()),
                        TextEntry::make('products.name')
                            ->label('Specific Products')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('All eligible products'),
                        TextEntry::make('source_label')
                            ->label('Source')
                            ->state(fn (CreditGrant $record): string => match (true) {
                                $record->source instanceof GiftCard => 'Gift card '.$record->source->code,
                                $record->grantedBy !== null => 'Admin issued',
                                default => 'Opening balance',
                            }),
                        TextEntry::make('grantedBy.fullName')
                            ->label('Issued By')
                            ->placeholder('System'),
                        TextEntry::make('created_at')
                            ->label('Issued At')
                            ->dateTime(),
                    ]),
                Section::make('Revocation')
                    ->columns(3)
                    ->visible(fn (CreditGrant $record): bool => $record->revoked_at !== null)
                    ->schema([
                        TextEntry::make('revoked_at')->dateTime(),
                        TextEntry::make('revokedBy.fullName')->label('Revoked By'),
                        TextEntry::make('revocation_reason')->label('Reason'),
                    ]),
                Section::make('Activity')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('transactions')
                            ->hiddenLabel()
                            ->grid(4)
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Date')
                                    ->dateTime(),
                                TextEntry::make('type')
                                    ->badge(),
                                TextEntry::make('amount')
                                    ->moneyCents(),
                                TextEntry::make('description')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
