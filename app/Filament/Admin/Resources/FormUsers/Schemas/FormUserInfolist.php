<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('form.name')
                            ->label('Form'),
                        TextEntry::make('user.full_name')
                            ->label('User'),
                        TextEntry::make('student.full_name')
                            ->label('Student')
                            ->placeholder('-'),
                        TextEntry::make('signature')
                            ->label('Signature')
                            ->placeholder('-'),
                        TextEntry::make('date_signed')
                            ->label('Date Signed')
                            ->date()
                            ->placeholder('-'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
