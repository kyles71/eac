<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryImageEntry::make('avatar')
                    ->collection('avatars')
                    // ->conversion('thumb')
                    ->circular(),
                SpatieMediaLibraryImageEntry::make('staff_photo')
                    ->label('Staff Photo')
                    ->collection('staff-photo'),
                // ->conversion('thumb'),
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
                TextEntry::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->listWithLineBreaks(),
                TextEntry::make('email'),
                TextEntry::make('email_verified_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
                TextEntry::make('two_factor_confirmed_at')
                    ->dateTime(),
            ]);
    }
}
