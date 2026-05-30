<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class StudentWaiver
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('EAC Medical Waiver & Media Release Form')
                    ->schema([
                        Text::make('This form must be completed for each student in order to participate in Elite Arts Company events; one form is required per student per dance year (September - August). This form must be completed by a parent or legal guardian if the student is under the age of 18.')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Personal Information')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('student_name')
                            ->label('Student Name')
                            ->helperText('Please enter first and last name of student.')
                            ->maxLength(255)
                            ->required(),
                        DatePicker::make('student_birth_date')
                            ->label('Student Birth Date')
                            ->required(),
                        Textarea::make('student_home_address')
                            ->label('Student Home Address')
                            ->helperText('Please enter home address of the student.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->required(),
                        TextInput::make('student_email')
                            ->label('Student Email')
                            ->helperText('Please enter email if applicable.')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('signer_name')
                            ->label('Your Name')
                            ->helperText('Please enter your first and last name.')
                            ->maxLength(255)
                            ->required(),
                        Radio::make('signer_relationship')
                            ->label('What is your relationship to the student?')
                            ->options([
                                'Mother' => 'Mother',
                                'Father' => 'Father',
                                'Legal Guardian' => 'Legal Guardian',
                                'Self - I am 18+' => 'Self - I am 18+',
                                'Other' => 'Other',
                            ])
                            ->required(),
                        TextInput::make('contact_phone')
                            ->label('Contact Information - Phone Number')
                            ->helperText('Please enter the best number to reach you at.')
                            ->phone()
                            ->required(),
                        Radio::make('wants_text_updates')
                            ->label('Would you like to enroll in EAC Text Message Updates?')
                            ->helperText('Text message updates are only utilized for urgent updates, such as class cancellation due to weather conditions or a health/safety issue.')
                            ->boolean('Yes', 'No')
                            ->required(),
                        TextInput::make('text_update_phone')
                            ->label('Text Message Updates Phone Number')
                            ->helperText('If you are enrolling in EAC Text Message Updates, please enter phone number for which you would like to receive text messages.')
                            ->phone()
                            ->visible(fn (Get $get): bool => (bool) $get('wants_text_updates'))
                            ->required(fn (Get $get): bool => (bool) $get('wants_text_updates')),
                        TextInput::make('contact_email')
                            ->label('Contact Information - Email Address')
                            ->helperText('Please enter the best email address to reach you at.')
                            ->email()
                            ->maxLength(255)
                            ->required(),
                        Repeater::make('emergency_contacts')
                            ->label('Emergency Contacts')
                            ->columnSpanFull()
                            ->columns(2)
                            ->relationship('emergencyContacts')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Name')
                                    ->maxLength(255)
                                    ->required(),
                                TextInput::make('relationship')
                                    ->label('Relationship')
                                    ->maxLength(255)
                                    ->required(),
                                TextInput::make('phone_number')
                                    ->label('Phone Number')
                                    ->phone()
                                    ->required(),
                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->required(),
                            ])
                            ->minItems(1)
                            ->defaultItems(2)
                            ->reorderable(false)
                            ->required(),
                        Radio::make('heard_about')
                            ->label('How did you hear about Elite Arts Company?')
                            ->options([
                                "I'm an existing student" => "I'm an existing student",
                                "I'm a former student" => "I'm a former student",
                                'Santa Parade' => 'Santa Parade',
                                'Bring a Friend Day' => 'Bring a Friend Day',
                                'From a friend' => 'From a friend',
                                'Facebook' => 'Facebook',
                                'Instagram' => 'Instagram',
                                'TikTok' => 'TikTok',
                                'Radio Advertisement' => 'Radio Advertisement',
                                'In-person Community Event' => 'In-person Community Event',
                                'Other' => 'Other',
                            ])
                            ->columnSpanFull()
                            ->required(),
                    ]),

                Section::make('Medical Waiver')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('As this Medical Waiver covers the entire event year (September - August), please notify Elite Arts Company at EACDance@outlook.com if any medical information noted on this form changes throughout the event year.')
                            ->columnSpanFull(),
                        Textarea::make('allergies')
                            ->label('Please enter any allergies that your student has.')
                            ->helperText('If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('medical_conditions')
                            ->label('Please enter any medical conditions your student is currently being treated for.')
                            ->helperText('Examples: asthma, breathing problems, heart conditions, bone/joint/muscle conditions, conditions affecting eyesight/depth perception or hearing. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('past_injuries')
                            ->label('Please list any past injuries treated by a medical professional.')
                            ->helperText('Examples: broken bones, concussions, fractures, dislocations. Please include date/year of injury. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('medications')
                            ->label('Please list any medication the student may take/need during class.')
                            ->helperText('Include over the counter and prescriptions. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Text::make('As guardian of the aforementioned minor, I authorize the designated adult to administer general first aid treatment for minor injuries or illnesses and to seek professional emergency personnel for severe injury or illness. Elite Arts Company, Natalie Schweikert, Melissa Schwab, and the EAC Staff are not liable for medical treatment costs or injuries.')
                            ->columnSpanFull(),
                        Checkbox::make('medical_release_consent')
                            ->label('I consent')
                            ->accepted()
                            ->required(),
                        Textarea::make('behavioral_notes')
                            ->label('Does your dancer have any attitude, behavioral, or social/emotional challenges that we should be aware of?')
                            ->helperText('Examples: ADHD, OCD, anxiety, etc.')
                            ->rows(3)
                            ->columnSpanFull(),
                        DatePicker::make('medical_release_signed_on')
                            ->label("Today's Date")
                            ->helperText('Please enter today\'s date to validate your electronic signature.')
                            ->required(),
                    ]),

                Section::make('EAC Health & Safety Policy')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('Elite Arts Company follows State regulations and CDC recommendations in regards to health safety and precautionary measures. By answering below, you are providing your electronic signature.')
                            ->columnSpanFull(),
                        Checkbox::make('health_safety_policy_consent')
                            ->label('I have read, understood, and agree to comply with the EAC Health & Safety Policy.')
                            ->accepted()
                            ->columnSpanFull()
                            ->required(),
                        DatePicker::make('health_safety_policy_signed_on')
                            ->label("Today's Date")
                            ->helperText('Please enter today\'s date to validate your electronic signature on the above Health & Safety Policy.')
                            ->required(),
                    ]),

                Section::make('Media Release')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('As guardian of the aforementioned minor, I grant my authorization and consent for Elite Arts Company, Melissa Schwab, and Natalie Schweikert to use photos or videos of my child during class times for promotional purposes. By answering below, you are providing your electronic signature.')
                            ->columnSpanFull(),
                        Radio::make('media_release_consent')
                            ->label('Media Release Consent')
                            ->boolean('I consent', 'I do not consent')
                            ->required(),
                        DatePicker::make('media_release_signed_on')
                            ->label("Today's Date")
                            ->helperText('Please enter today\'s date to validate your electronic signature.')
                            ->required(),
                    ]),
            ]);
    }
}
