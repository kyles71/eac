<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Schemas;

use App\Enums\FormTypes;
use App\Filament\Shared\Schemas\StudentWaiverInfolist;
use App\Models\FormUser;
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
                        TextEntry::make('student.fullName')
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
                ...collect(StudentWaiverInfolist::components())
                    ->each(fn (Section $section) => $section->visible(
                        fn (FormUser $record): bool => $record->form?->form_type === FormTypes::StudentWaiver
                    ))
                    ->all(),
            ]);
    }
}
