<?php

declare(strict_types=1);

namespace App\Forms;

use App\Models\LegalDocumentVersion;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;

final readonly class DefaultFormDefinitions
{
    public const StudentHomeAddress = 'e7999a7f-8e13-4fe7-9300-4a1fbf8cf737';

    public const SignerRelationship = '086a7728-dd8f-4b0f-8256-b2f72a29d6bd';

    public const MedicalConditions = '54f32622-74bd-47cf-b506-5d2eb4bc6b75';

    public const Allergies = '43250ea9-586c-4c79-93ab-fbe0bd963a87';

    public const PastInjuries = 'e0222212-c61c-47ea-884d-f42d93de4b37';

    public const Medications = '45fd32ab-8755-43ca-a088-c8706730bbfc';

    public const MedicalReleaseConsent = 'dc521d4c-b456-4566-838a-80f19c358683';

    public const BehavioralNotes = '0315ac9b-61e3-4644-9877-a5c650821220';

    public const MedicalReleaseSignedOn = '9cc1d89d-09c7-4965-8cbc-2e1511709ff3';

    public const HealthSafetyPolicyConsent = 'c7d355dd-eb64-48d1-b702-f7a6b605a9a1';

    public const HealthSafetyPolicySignedOn = 'dd45e34e-5ea6-47c3-9863-9d23394e86ea';

    public const MediaReleaseConsent = '618fc2fa-ccbe-4a8f-a3ce-f25dd64106eb';

    public const MediaReleaseSignedOn = '1c2f6af6-ab49-477d-b96c-c75697393530';

    public const EmergencyContacts = '938803bf-a60c-408e-a746-b7e870e94660';

    public const ShowcaseParticipation = '645fb77b-d2e0-4937-a0ce-638bfcd8a8b7';

    private const string LinkClasses = 'fi-link fi-size-sm fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function medicalWaiver(): array
    {
        return [
            $this->section(
                '1e378a6e-206e-4e97-b95d-09fc0e4298da',
                'EAC Medical Waiver & Media Release Form',
                [
                    $this->instructions(
                        '4f151e02-9bb0-4672-a24c-50d16850f9dd',
                        'This form must be completed for each student in order to participate in Elite Arts Company events; one form is required per student per dance year (September - August). This form must be completed by a parent or legal guardian if the student is under the age of 18.',
                    ),
                ],
            ),
            $this->section(
                '603f72d4-4855-41cb-a22c-6bce1f4cb672',
                'Personal Information',
                [
                    [
                        'type' => 'subject',
                        'data' => [
                            'key' => '101e3ff8-adf6-4d3a-882a-84d6b1f99dc7',
                            'label' => 'Student',
                        ],
                    ],
                    $this->question(
                        'select',
                        self::SignerRelationship,
                        'What is your relationship to the student?',
                        'student_waiver.signer_relationship',
                        options: [
                            'Mother' => 'Mother',
                            'Father' => 'Father',
                            'Legal Guardian' => 'Legal Guardian',
                            'Self - I am 18+' => 'Self - I am 18+',
                        ],
                    ),
                    $this->question(
                        'long_text',
                        self::StudentHomeAddress,
                        'Student Home Address',
                        'student_waiver.student_home_address',
                        help: 'Please enter home address of the student.',
                    ),
                    [
                        'type' => 'emergency_contacts',
                        'data' => [
                            'key' => self::EmergencyContacts,
                            'label' => 'Emergency Contacts',
                            'min_items' => 1,
                            'text_updates_help' => $this->textMessageUpdatesPolicyHelperText(),
                        ],
                    ],
                ],
                columns: 2,
            ),
            $this->section(
                '0b19ed95-435f-4dde-bcb0-05a30240cd99',
                'Medical Waiver',
                [
                    $this->instructions(
                        'f6a30470-6950-4c6d-9210-a1ba7b3a0513',
                        'As this Medical Waiver covers the entire event year (September - August), please notify Elite Arts Company at EACDance@outlook.com if any medical information noted on this form changes throughout the event year.',
                    ),
                    $this->question('long_text', self::Allergies, 'Please enter any allergies that your student has.', 'student_waiver.allergies', 'If none, please type "N/A".'),
                    $this->question('long_text', self::MedicalConditions, 'Please enter any medical conditions your student is currently being treated for.', 'student_waiver.medical_conditions', 'Examples: asthma, breathing problems, heart conditions, bone/joint/muscle conditions, conditions affecting eyesight/depth perception or hearing. If none, please type "N/A".'),
                    $this->question('long_text', self::PastInjuries, 'Please list any past injuries treated by a medical professional.', 'student_waiver.past_injuries', 'Examples: broken bones, concussions, fractures, dislocations. Please include date/year of injury. If none, please type "N/A".'),
                    $this->question('long_text', self::Medications, 'Please list any medication the student may take/need during class.', 'student_waiver.medications', 'Include over the counter and prescriptions. If none, please type "N/A".'),
                    $this->instructions(
                        '637a63f9-cf95-4213-a18f-05f7bc472aaa',
                        'Consent to Medical Treatment',
                    ),
                    $this->instructions(
                        'c8101f06-a319-4fbc-a59e-3c9658a88715',
                        'As the parent/legal guardian of the minor dancer listed above, I authorize Elite Arts Company, LLC, its owners, staff, and instructors to provide general first aid for minor injuries or illnesses that may occur during EAC classes, events, rehearsals, performances, or other studio-related activities.',
                    ),
                    $this->instructions(
                        '0ea2b7a4-9c0d-4a45-9ed7-0f5b25c9ce49',
                        'In the event of a more serious injury, illness, or medical emergency, I authorize EAC to contact emergency medical personnel and assist in obtaining medical care, transportation, and treatment as deemed necessary by emergency responders or licensed medical professionals.',
                    ),
                    $this->instructions(
                        '286db35e-00c7-4f4a-a89d-9a2e6d6908f3',
                        'I understand that Elite Arts Company, LLC, its owners, staff, instructors, and designated adults are not responsible for medical costs, emergency transportation, treatment expenses, injuries, illnesses, or medical conditions that may occur during or as a result of participation in EAC activities.',
                    ),
                    $this->question('checkbox', self::MedicalReleaseConsent, 'I consent', 'student_waiver.medical_release_consent', accepted: true),
                    $this->question('long_text', self::BehavioralNotes, 'Does your dancer have any attitude, behavioral, or social/emotional challenges that we should be aware of?', 'student_waiver.behavioral_notes', 'Examples: ADHD, OCD, anxiety, etc.', required: false),
                    $this->question('date', self::MedicalReleaseSignedOn, 'Today\'s Date', 'student_waiver.medical_release_signed_on', 'Please enter today\'s date to validate your electronic signature.', defaultToday: true),
                ],
                columns: 2,
            ),
            $this->section(
                '06d8c32b-3875-42f2-8616-d3792ff421a2',
                'EAC Health & Safety Policy',
                [
                    $this->question('checkbox', self::HealthSafetyPolicyConsent, 'I have read, understood, and agree to comply with the EAC Health & Safety Policy.', 'student_waiver.health_safety_policy_consent', $this->legalDocumentLink(HealthSafetyPolicy::currentVersion(), 'View and print the EAC Health & Safety Policy'), accepted: true, helpIsHtml: true),
                    $this->question('date', self::HealthSafetyPolicySignedOn, 'Today\'s Date', 'student_waiver.health_safety_policy_signed_on', 'Please enter today\'s date to validate your electronic signature on the above Health & Safety Policy.', defaultToday: true),
                ],
                columns: 2,
            ),
            $this->section(
                'b2834bda-06be-4f13-b7ca-5fa017d008a1',
                'Media Release',
                [
                    $this->instructions(
                        '510b2a6f-251d-480f-8111-4c35a2cb26a1',
                        'As the parent/guardian of the participating minor, I grant permission for Elite Arts Company, LLC, including its owners, staff, instructors, and authorized representatives, to photograph, video record, and/or otherwise capture media of my child during classes, rehearsals, performances, events, activities, and other studio-related programming.',
                    ),
                    $this->instructions(
                        'e55b1e53-ae49-4a5e-b13d-257f3499ebd7',
                        'I understand and agree that these photographs, videos, and other media may be used for promotional, advertising, educational, informational, or marketing purposes, including but not limited to: Elite Arts Company’s website, social media pages, printed materials, digital advertisements, newspaper features, videos, commercials, and other studio communications.',
                    ),
                    $this->instructions(
                        '6966e9c6-7f59-4085-99d6-ef188d07ef48',
                        'Elite Arts Company will make reasonable efforts to use media in a respectful and appropriate manner and will not knowingly use any photo, video, or footage that is inappropriate, unsafe, or harmful to my child’s reputation or image.',
                    ),
                    $this->instructions(
                        'bc293ed4-a47b-4605-8251-bad2313b4bc1',
                        'If my child is requested to participate in an interview or provide a personal statement for promotional use, I understand that I will be notified at least five days in advance and will have the opportunity to be present.',
                    ),
                    $this->instructions(
                        'c30c514b-a646-4147-9a60-7f5667fdb879',
                        'I understand that if I do not consent to this media release, my child may be excluded from certain optional media-related activities outside of regular class participation, such as Calendar Photo Day, Team Media Day, promotional photo shoots, or similar events.',
                    ),
                    $this->instructions(
                        '94ee2074-5951-4d35-8a6b-645e073e57bd',
                        'I understand that neither I nor my child will receive monetary compensation for the use of any photographs, videos, or other media.',
                    ),
                    $this->instructions(
                        'e86809ab-19e1-44a9-86f7-7b8e3129ce92',
                        'By signing, I acknowledge that I have read and understand this Media Release and grant permission for Elite Arts Company, LLC to use photos, videos, and other media of my child for studio-related promotional, advertising, and communication purposes.',
                    ),
                    $this->question('toggle', self::MediaReleaseConsent, 'Media Release Consent', 'student_waiver.media_release_consent'),
                    $this->question('date', self::MediaReleaseSignedOn, 'Today\'s Date', 'student_waiver.media_release_signed_on', 'Please enter today\'s date to validate your electronic signature.', defaultToday: true),
                ],
                columns: 2,
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function showcaseParticipation(): array
    {
        return [
            $this->section(
                '068c9b1e-19e3-47b4-b506-136285ec42bd',
                'Showcase Participation',
                [
                    [
                        'type' => 'subject',
                        'data' => [
                            'key' => '9599df48-2786-4bea-b605-c33d294f24ab',
                            'label' => 'Student',
                        ],
                    ],
                    $this->question('toggle', self::ShowcaseParticipation, 'Is Participating', 'showcase_participation.is_participating'),
                ],
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function section(string $key, string $heading, array $components, int $columns = 1): array
    {
        return [
            'type' => 'section',
            'data' => compact('key', 'heading', 'columns', 'components'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instructions(string $key, string $content, bool $isHtml = false): array
    {
        return [
            'type' => 'text',
            'data' => [
                'key' => $key,
                'content' => $content,
                'is_html' => $isHtml,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private function question(
        string $type,
        string $key,
        string $label,
        string $mapping,
        ?string $help = null,
        bool $required = true,
        bool $accepted = false,
        bool $defaultToday = false,
        array $options = [],
        bool $helpIsHtml = false,
    ): array {
        return [
            'type' => $type,
            'data' => [
                'key' => $key,
                'label' => $label,
                'mapping' => $mapping,
                'help' => $help,
                'required' => $required,
                'accepted' => $accepted,
                'default_today' => $defaultToday,
                'options' => $options,
                'help_is_html' => $helpIsHtml,
                'column_span' => 2,
            ],
        ];
    }

    private function textMessageUpdatesPolicyHelperText(): string
    {
        $helperText = 'Text message updates are only utilized for urgent updates, such as class cancellation due to weather conditions or a health/safety issue.';
        $link = $this->legalDocumentLink(TextMessageUpdatesPolicy::currentVersion(), 'Click here to view our full Text Message Updates Policy');

        return $link === null ? $helperText : $helperText.' '.$link;
    }

    private function legalDocumentLink(?LegalDocumentVersion $version, string $label): ?string
    {
        if ($version === null) {
            return null;
        }

        return '<a class="'.self::LinkClasses.'" href="'.e(route('legal-documents.versions.show', $version)).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a>';
    }
}
