<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

final class StudentContactForm
{
    public static function configure(Schema $schema, ?Action $saveAction = null): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('fullName')
                            ->label('Name')
                            ->state(fn (Student $record): mixed => $record->getAttribute('fullName')),
                        TextEntry::make('birthdate')
                            ->state(fn (Student $record) => $record->birthdate)
                            ->date(),
                        TextEntry::make('age')
                            ->state(fn (Student $record): mixed => $record->getAttribute('age')),
                        TextInput::make('nickname')
                            ->maxLength(255),
                        Repeater::make('additional_emails')
                            ->label('Additional Emails')
                            ->helperText('Add up to three email addresses associated with this student.')
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->distinct()
                                    ->required(),
                                Flex::make([
                                    Select::make('relationship_option')
                                        ->label('Relationship')
                                        ->options([
                                            'Mother' => 'Mother',
                                            'Father' => 'Father',
                                            'Dancer' => 'Dancer',
                                            'Other' => 'Other',
                                        ])
                                        ->searchable(false)
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                                            'relationship',
                                            $state === 'Other' ? null : $state,
                                        ))
                                        ->required(),
                                    TextInput::make('relationship')
                                        ->label('Other Relationship')
                                        ->maxLength(255)
                                        ->visible(fn (Get $get): bool => $get('relationship_option') === 'Other')
                                        ->required(fn (Get $get): bool => $get('relationship_option') === 'Other'),
                                ]),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->maxItems(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add email'),
                    ])
                    ->footer($saveAction === null ? [] : [$saveAction]),
            ]);
    }
}
