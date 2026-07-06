<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Filament\User\Resources\Students\Schemas\StudentForm;
use App\Models\FormUser;
use App\Models\LegalDocumentVersion;
use App\Models\Student;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

final class StudentWaiver
{
    private const string LINK_CLASSES = 'fi-link fi-size-sm fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400';

    public static function configure(Schema $schema, bool $withRelationships = true): Schema
    {
        $textMessageUpdatesPolicyVersion = TextMessageUpdatesPolicy::currentVersion();
        $healthSafetyPolicyVersion = HealthSafetyPolicy::currentVersion();

        $studentSelection = Grid::make(2)
            ->columnSpanFull()
            ->schema([
                Select::make('student_id')
                    ->label('Student')
                    ->disabled(fn (?FormUser $record): bool => $record?->student_id !== null)
                    ->searchable(false)
                    ->studentRelationship(
                        modifyQueryUsing: fn (Builder $query, ?FormUser $record): Builder => $query
                            ->where('user_id', auth()->id())
                            ->when(
                                $record?->student_id !== null,
                                fn (Builder $query): Builder => $query->whereKey($record->student_id),
                                fn (Builder $query): Builder => $query->whereDoesntHave(
                                    'forms',
                                    fn (Builder $query): Builder => $query->where('form_id', $record?->form_id),
                                ),
                            ),
                    )
                    ->createOptionForm(fn (Schema $schema): Schema => StudentForm::configure($schema))
                    ->createOptionUsing(function (array $data): int {
                        /** @var User $user */
                        $user = auth()->user();

                        return $user->students()->create($data)->getKey();
                    })
                    ->scopedExists(
                        model: Student::class,
                        column: 'id',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('user_id', auth()->id()),
                    )
                    ->required(),
            ]);

        $withRelationships
            ? $studentSelection->relationship('userForm')
            : $studentSelection->statePath('userForm');

        $emergencyContacts = Repeater::make('emergency_contacts')
            ->label('Emergency Contacts')
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->maxLength(255)
                    ->required(),
                Flex::make([
                    Select::make('relationship_option')
                        ->label('Relationship')
                        ->searchable(false)
                        ->options([
                            'Mother' => 'Mother',
                            'Father' => 'Father',
                            'Guardian' => 'Guardian',
                            'Other' => 'Other',
                        ])
                        ->afterStateUpdatedJs(<<<'JS'
                            $set('relationship', $state === 'Other' ? null : $state)
                            JS)
                        ->required(),
                    TextInput::make('relationship')
                        ->label('Other Relationship')
                        ->maxLength(255)
                        ->visibleJs(<<<'JS'
                            $get('relationship_option') === 'Other'
                            JS)
                        ->required(fn (Get $get): bool => $get('relationship_option') === 'Other'),
                ]),
                TextInput::make('phone_number')
                    ->label('Phone Number')
                    ->phone()
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255)
                    ->required(),
                Radio::make('wants_text_updates')
                    ->label('Enroll this phone number in EAC Text Message Updates?')
                    ->helperText(self::textMessageUpdatesPolicyHelperText($textMessageUpdatesPolicyVersion))
                    ->boolean('Yes', 'No')
                    ->columnSpanFull()
                    ->required(),
            ])
            ->minItems(1)
            ->defaultItems(2)
            ->reorderable(false)
            ->required();

        if ($withRelationships) {
            $emergencyContacts
                ->relationship('emergencyContacts')
                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                    $relationship = $data['relationship'] ?? null;
                    $data['relationship_option'] = in_array($relationship, ['Mother', 'Father', 'Guardian'], true)
                        ? $relationship
                        : 'Other';

                    return $data;
                })
                ->mutateRelationshipDataBeforeCreateUsing(
                    fn (array $data): array => self::normalizeEmergencyContactRelationship($data),
                )
                ->mutateRelationshipDataBeforeSaveUsing(
                    fn (array $data): array => self::normalizeEmergencyContactRelationship($data),
                );
        }

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
                        $studentSelection,
                        Select::make('signer_relationship')
                            ->label('What is your relationship to the student?')
                            ->searchable(false)
                            ->options([
                                'Mother' => 'Mother',
                                'Father' => 'Father',
                                'Legal Guardian' => 'Legal Guardian',
                                'Self - I am 18+' => 'Self - I am 18+',
                            ])
                            ->required(),
                        Textarea::make('student_home_address')
                            ->label('Student Home Address')
                            ->aboveContent('Please enter home address of the student.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->required(),
                        $emergencyContacts,
                    ]),

                Section::make('Medical Waiver')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('As this Medical Waiver covers the entire event year (September - August), please notify Elite Arts Company at EACDance@outlook.com if any medical information noted on this form changes throughout the event year.')
                            ->columnSpanFull(),
                        Textarea::make('allergies')
                            ->label('Please enter any allergies that your student has.')
                            ->aboveContent('If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('medical_conditions')
                            ->label('Please enter any medical conditions your student is currently being treated for.')
                            ->aboveContent('Examples: asthma, breathing problems, heart conditions, bone/joint/muscle conditions, conditions affecting eyesight/depth perception or hearing. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('past_injuries')
                            ->label('Please list any past injuries treated by a medical professional.')
                            ->aboveContent('Examples: broken bones, concussions, fractures, dislocations. Please include date/year of injury. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Textarea::make('medications')
                            ->label('Please list any medication the student may take/need during class.')
                            ->aboveContent('Include over the counter and prescriptions. If none, please type "N/A".')
                            ->rows(3)
                            ->columnSpanFull()
                            ->required(),
                        Text::make('Consent to Medical Treatment')
                            ->weight(FontWeight::Bold)
                            ->columnSpanFull(),
                        Text::make('As the parent/legal guardian of the minor dancer listed above, I authorize Elite Arts Company, LLC, its owners, staff, and instructors to provide general first aid for minor injuries or illnesses that may occur during EAC classes, events, rehearsals, performances, or other studio-related activities.')
                            ->columnSpanFull(),
                        Text::make('In the event of a more serious injury, illness, or medical emergency, I authorize EAC to contact emergency medical personnel and assist in obtaining medical care, transportation, and treatment as deemed necessary by emergency responders or licensed medical professionals.')
                            ->columnSpanFull(),
                        Text::make('I understand that Elite Arts Company, LLC, its owners, staff, instructors, and designated adults are not responsible for medical costs, emergency transportation, treatment expenses, injuries, illnesses, or medical conditions that may occur during or as a result of participation in EAC activities.')
                            ->columnSpanFull(),
                        Checkbox::make('medical_release_consent')
                            ->label('I consent')
                            ->accepted()
                            ->required(),
                        Textarea::make('behavioral_notes')
                            ->label('Does your dancer have any attitude, behavioral, or social/emotional challenges that we should be aware of?')
                            ->aboveContent('Examples: ADHD, OCD, anxiety, etc.')
                            ->helperText('Please note: While we aim to adjust our instructional methods when possible to better support each dancer, we are unable to cater to every individual need. Our goal is to provide a positive learning environment for all, but certain challenges may require strategies beyond what we can accommodate within the class structure.')
                            ->rows(3)
                            ->columnSpanFull(),
                        self::signatureDateField(
                            'medical_release_signed_on',
                            'Please enter today\'s date to validate your electronic signature.',
                        ),
                    ]),

                Section::make('EAC Health & Safety Policy')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Checkbox::make('health_safety_policy_consent')
                            ->label('I have read, understood, and agree to comply with the EAC Health & Safety Policy.')
                            ->helperText(self::legalDocumentLink($healthSafetyPolicyVersion, 'View and print the EAC Health & Safety Policy'))
                            ->accepted()
                            ->columnSpanFull()
                            ->required(),
                        self::signatureDateField(
                            'health_safety_policy_signed_on',
                            'Please enter today\'s date to validate your electronic signature on the above Health & Safety Policy.',
                        ),
                    ]),

                Section::make('Media Release')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('As the parent/guardian of the participating minor, I grant permission for Elite Arts Company, LLC, including its owners, staff, instructors, and authorized representatives, to photograph, video record, and/or otherwise capture media of my child during classes, rehearsals, performances, events, activities, and other studio-related programming.')
                            ->columnSpanFull(),
                        Text::make('I understand and agree that these photographs, videos, and other media may be used for promotional, advertising, educational, informational, or marketing purposes, including but not limited to: Elite Arts Company’s website, social media pages, printed materials, digital advertisements, newspaper features, videos, commercials, and other studio communications.')
                            ->columnSpanFull(),
                        Text::make('Elite Arts Company will make reasonable efforts to use media in a respectful and appropriate manner and will not knowingly use any photo, video, or footage that is inappropriate, unsafe, or harmful to my child’s reputation or image.')
                            ->columnSpanFull(),
                        Text::make('If my child is requested to participate in an interview or provide a personal statement for promotional use, I understand that I will be notified at least five days in advance and will have the opportunity to be present.')
                            ->columnSpanFull(),
                        Text::make('I understand that if I do not consent to this media release, my child may be excluded from certain optional media-related activities outside of regular class participation, such as Calendar Photo Day, Team Media Day, promotional photo shoots, or similar events.')
                            ->columnSpanFull(),
                        Text::make('I understand that neither I nor my child will receive monetary compensation for the use of any photographs, videos, or other media.')
                            ->columnSpanFull(),
                        Text::make('By signing, I acknowledge that I have read and understand this Media Release and grant permission for Elite Arts Company, LLC to use photos, videos, and other media of my child for studio-related promotional, advertising, and communication purposes.')
                            ->columnSpanFull(),
                        Radio::make('media_release_consent')
                            ->label('Media Release Consent')
                            ->boolean('I consent', 'I do not consent')
                            ->required(),
                        self::signatureDateField(
                            'media_release_signed_on',
                            'Please enter today\'s date to validate your electronic signature.',
                        ),
                    ]),
            ]);
    }

    private static function textMessageUpdatesPolicyHelperText(?LegalDocumentVersion $version): string|Htmlable
    {
        $helperText = 'Text message updates are only utilized for urgent updates, such as class cancellation due to weather conditions or a health/safety issue.';

        if ($version === null) {
            return $helperText;
        }

        return new HtmlString(e($helperText).' '.self::legalDocumentLinkHtml($version, 'Click here to view our full Text Message Updates Policy'));
    }

    private static function legalDocumentLink(?LegalDocumentVersion $version, string $label): ?HtmlString
    {
        if ($version === null) {
            return null;
        }

        return new HtmlString(self::legalDocumentLinkHtml($version, $label));
    }

    private static function legalDocumentLinkHtml(LegalDocumentVersion $version, string $label): string
    {
        return '<a class="'.self::LINK_CLASSES.'" href="'.e(route('legal-documents.versions.show', $version)).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
    }

    private static function today(): string
    {
        return now((string) config('app.display_timezone', config('app.timezone')))->toDateString();
    }

    private static function signatureDateField(string $name, string $aboveContent): DatePicker
    {
        return DatePicker::make($name)
            ->label("Today's Date")
            ->aboveContent($aboveContent)
            ->required()
            ->default(fn (): string => self::today())
            ->afterStateHydrated(fn (DatePicker $component, mixed $state) => blank($state)
                ? $component->state(self::today())
                : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeEmergencyContactRelationship(array $data): array
    {
        if (($data['relationship_option'] ?? null) !== 'Other') {
            $data['relationship'] = $data['relationship_option'] ?? null;
        }

        unset($data['relationship_option']);

        return $data;
    }
}
