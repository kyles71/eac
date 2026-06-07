<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

final class StudentWaiverInfolist
{
    /**
     * @return array<Section>
     */
    public static function components(): array
    {
        return [
            Section::make('Personal Information')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('responseable.student_home_address')
                        ->label('Student Home Address'),
                    TextEntry::make('responseable.signer_relationship')
                        ->label('Signer Relationship'),
                    RepeatableEntry::make('responseable.emergencyContacts')
                        ->label('Emergency Contacts')
                        ->columnSpanFull()
                        ->table([
                            TableColumn::make('Name'),
                            TableColumn::make('Relationship'),
                            TableColumn::make('Phone'),
                            TableColumn::make('Email'),
                            TableColumn::make('Text Updates'),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('relationship'),
                            TextEntry::make('phone_number'),
                            TextEntry::make('email'),
                            IconEntry::make('wants_text_updates')
                                ->boolean(),
                        ]),
                ]),
            Section::make('Medical Waiver')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('responseable.allergies')
                        ->label('Allergies')
                        ->columnSpanFull(),
                    TextEntry::make('responseable.medical_conditions')
                        ->label('Medical Conditions')
                        ->columnSpanFull(),
                    TextEntry::make('responseable.past_injuries')
                        ->label('Past Injuries')
                        ->columnSpanFull(),
                    TextEntry::make('responseable.medications')
                        ->label('Medications')
                        ->columnSpanFull(),
                    TextEntry::make('responseable.behavioral_notes')
                        ->label('Behavioral / Social-Emotional Notes')
                        ->placeholder('None')
                        ->columnSpanFull(),
                    IconEntry::make('responseable.medical_release_consent')
                        ->label('Medical Release Consent')
                        ->boolean(),
                    TextEntry::make('responseable.medical_release_signed_on')
                        ->label('Medical Release Signed On')
                        ->date(),
                ]),
            Section::make('Health, Safety, and Media Release')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    IconEntry::make('responseable.health_safety_policy_consent')
                        ->label('Health & Safety Policy Consent')
                        ->boolean(),
                    TextEntry::make('responseable.health_safety_policy_signed_on')
                        ->label('Health & Safety Policy Signed On')
                        ->date(),
                    IconEntry::make('responseable.media_release_consent')
                        ->label('Media Release Consent')
                        ->boolean(),
                    TextEntry::make('responseable.media_release_signed_on')
                        ->label('Media Release Signed On')
                        ->date(),
                ]),
        ];
    }
}
