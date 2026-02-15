<?php

namespace App\Filament\User\Resources\FormUsers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FormUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('form.name')
                    ->label('Form'),
                TextEntry::make('user.id')
                    ->label('User'),
                TextEntry::make('student.id')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('signature')
                    ->label('Signature'),
                TextEntry::make('date_signed')
                    ->label('Date Signed')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
