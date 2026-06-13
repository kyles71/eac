<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Filament\Shared\Schemas\CompetitionMembershipHistory;
use App\Models\Calendar;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\SpatieTagsEntry;
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
                    ]),
                Section::make('Access')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->listWithLineBreaks(),
                        SpatieTagsEntry::make('calendar_audience_tags')
                            ->label('Calendar Audience Tags')
                            ->type(Calendar::AUDIENCE_TAG_TYPE),
                    ]),
                Section::make('Staff Profile')
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('staff_photo')
                            ->label('Staff Photo')
                            ->collection('staff-photo')
                            ->disk(MediaDisks::private())
                            ->visibility('private'),
                        // ->conversion('thumb'),
                    ]),
                CompetitionMembershipHistory::make(),
                Section::make('Security & Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('email_verified_at')
                            ->label('Email Verified At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('two_factor_confirmed_at')
                            ->label('Two Factor Confirmed At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
