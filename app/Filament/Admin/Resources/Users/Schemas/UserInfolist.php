<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Filament\Shared\Schemas\CompetitionMembershipHistory;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('avatar')
                            ->collection('avatars')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            // ->conversion('thumb')
                            ->circular(),
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('email'),
                        IconEntry::make('uses_mfa')
                            ->label('Uses MFA')
                            ->state(fn (User $record): bool => filled($record->getAppAuthenticationSecret()))
                            ->boolean(),
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->listWithLineBreaks(),
                        TextEntry::make('last_login_at')
                            ->label('Last Logged In')
                            ->dateTime()
                            ->placeholder('Never'),
                        TextEntry::make('created_at')
                            ->label('Member Since')
                            ->dateTime(),
                    ]),
                Section::make('Staff Profile')
                    ->collapsed()
                    ->columnSpanFull()
                    ->visible(fn (?User $record): bool => $record?->isStaffMember() ?? false)
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('staff_photo')
                            ->label('Staff Photo')
                            ->collection('staff-photo')
                            ->disk(MediaDisks::private())
                            ->visibility('private'),
                        // ->conversion('thumb'),
                        TextEntry::make('staff_bio')
                            ->label('Staff Bio')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                CompetitionMembershipHistory::make(),
            ]);
    }
}
