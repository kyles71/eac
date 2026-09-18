<?php

declare(strict_types=1);

namespace App\Forms\Eac\Blocks;

use App\Forms\Eac\EacFormContentProvider;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kyle\FilamentFormBuilder\Contracts\FormBlockComparisonProvider;
use Kyle\FilamentFormBuilder\Contracts\FormBlockInfolistProvider;
use Kyle\FilamentFormBuilder\Contracts\FormBlockPickerTooltipProvider;
use Kyle\FilamentFormBuilder\Contracts\FormBlockProvider;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Models\FormAssignment;
use Kyle\FilamentFormBuilder\Support\FormBlockDesignerDescriptor;
use Kyle\FilamentFormBuilder\Support\FormBlockRuntimeContext;
use Kyle\FilamentFormBuilder\Support\PhoneNumber;

final readonly class EmergencyContactsBlock implements FormBlockComparisonProvider, FormBlockInfolistProvider, FormBlockPickerTooltipProvider, FormBlockProvider
{
    public function type(): string
    {
        return 'emergency_contacts';
    }

    public function label(array $data): string
    {
        return (string) ($data['label'] ?? 'Emergency Contacts');
    }

    public function blockPickerTooltip(): string
    {
        return 'Collects one or more emergency contacts, including contact details and text-message consent.';
    }

    public function designer(): FormBlockDesignerDescriptor
    {
        return new FormBlockDesignerDescriptor(
            type: $this->type(),
            category: 'Application blocks',
            label: 'Emergency Contacts',
            icon: 'heroicon-o-user-group',
            helpText: $this->blockPickerTooltip(),
            defaultData: [
                'label' => 'Emergency Contacts',
                'min_items' => 1,
                'default_items' => 2,
            ],
            inspectorSchema: fn (): array => [
                TextInput::make('label')
                    ->required()
                    ->live(onBlur: true),
                TextInput::make('min_items')
                    ->label('Minimum contacts')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('default_items')
                    ->label('Default contacts')
                    ->helperText('Number of blank contact rows shown when the form is first opened.')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Select::make('text_message_policy_reference')
                    ->label('Text Message Updates Policy version')
                    ->helperText('This exact published version is pinned to every response using this block.')
                    ->options(fn (): array => app(EacFormContentProvider::class)->textMessageUpdatesPolicyOptions())
                    ->searchable()
                    ->required(),
            ],
            canvasSummary: fn (array $data): array => [
                'Minimum contacts' => max(1, (int) ($data['min_items'] ?? 1)),
                'Default contacts' => max(1, (int) ($data['default_items'] ?? 2)),
            ],
        );
    }

    public function validate(array $data): void
    {
        if (! is_string($data['key'] ?? null) || ! Str::isUuid($data['key'])) {
            throw new InvalidArgumentException('Every emergency contacts block must have a stable UUID key.');
        }

        if ((int) ($data['min_items'] ?? 0) < 1) {
            throw new InvalidArgumentException('Emergency contacts must require at least one contact.');
        }

        if ((int) ($data['default_items'] ?? 2) < (int) $data['min_items']) {
            throw new InvalidArgumentException('Default emergency contacts cannot be fewer than the required minimum.');
        }

        $reference = $data['text_message_policy_reference'] ?? null;

        if (! is_string($reference) || ! str_starts_with($reference, EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix)) {
            throw new InvalidArgumentException('Every emergency contacts block must select a published Text Message Updates Policy version.');
        }

        app(EacFormContentProvider::class)->reference($reference);
    }

    public function fields(array $data): array
    {
        $blockKey = (string) $data['key'];

        return collect([
            'name' => ['Emergency contact name', FormAnswerType::String],
            'relationship' => ['Emergency contact relationship', FormAnswerType::String],
            'phone_number' => ['Emergency contact phone number', FormAnswerType::String],
            'email' => ['Emergency contact email', FormAnswerType::String],
            'wants_text_updates' => ['Emergency contact text updates', FormAnswerType::Boolean],
        ])->map(function (array $definition, string $subKey) use ($blockKey): array {
            [$label, $answerType] = $definition;

            return [
                'key' => "{$blockKey}.{$subKey}",
                'answer_type' => $answerType,
                'mapping' => null,
                'label' => $label,
                'block_key' => $blockKey,
                'sub_key' => $subKey,
            ];
        })->values()->all();
    }

    public function validationRules(array $data): array
    {
        $key = (string) $data['key'];
        $minimum = max(1, (int) ($data['min_items'] ?? 1));

        return [
            "answers.{$key}" => ['required', 'array', "min:{$minimum}"],
            "answers.{$key}.*.name" => ['required', 'string', 'max:255'],
            "answers.{$key}.*.relationship" => ['required', 'string', 'max:255'],
            "answers.{$key}.*.phone_number" => ['required', 'string', new PhoneNumber],
            "answers.{$key}.*.email" => ['required', 'email', 'max:255'],
            "answers.{$key}.*.wants_text_updates" => ['required', 'boolean'],
        ];
    }

    public function infolist(array $data, ?FormAssignment $assignment, string $statePathPrefix): Component
    {
        $key = (string) $data['key'];
        $reference = (string) $data['text_message_policy_reference'];

        return RepeatableEntry::make("{$statePathPrefix}.answers.{$key}")
            ->label($this->label($data))
            ->helperText(app(EacFormContentProvider::class)->textMessageUpdatesHelp($reference))
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextEntry::make('name')->label('Name'),
                TextEntry::make('relationship')->label('Relationship'),
                TextEntry::make('phone_number')->label('Phone number'),
                TextEntry::make('email')->label('Email'),
                IconEntry::make('wants_text_updates')->label('Wants text updates')->boolean(),
            ]);
    }

    public function compile(array $data, ?FormAssignment $assignment, FormBlockRuntimeContext $context): Component
    {
        $key = (string) $data['key'];
        $minimum = max(1, (int) ($data['min_items'] ?? 1));
        $defaultItems = max($minimum, (int) ($data['default_items'] ?? 2));
        $textMessagePolicyReference = is_string($data['text_message_policy_reference'] ?? null)
            ? $data['text_message_policy_reference']
            : null;
        $relationship = $context->globallyDisabled && $assignment !== null
            ? TextInput::make('relationship')
                ->label('Relationship')
            : Flex::make([
                Select::make('relationship_option')
                    ->label('Relationship')
                    ->searchable(false)
                    ->options([
                        'Mother' => 'Mother',
                        'Father' => 'Father',
                        'Guardian' => 'Guardian',
                        'Other' => 'Other',
                    ])
                    ->afterStateHydrated(function (Get $get, Set $set): void {
                        $relationship = $get('relationship');

                        if (blank($relationship)) {
                            return;
                        }

                        $set('relationship_option', in_array($relationship, ['Mother', 'Father', 'Guardian'], true)
                            ? $relationship
                            : 'Other');
                    })
                    ->afterStateUpdatedJs(<<<'JS'
                        $set('relationship', $state === 'Other' ? null : $state)
                        JS)
                    ->required(fn (Get $get): bool => blank($get('relationship'))),
                TextInput::make('relationship')
                    ->label('Other Relationship')
                    ->maxLength(255)
                    ->visibleJs(<<<'JS'
                        $get('relationship_option') === 'Other'
                        JS)
                    ->required(fn (Get $get): bool => $get('relationship_option') === 'Other'),
            ]);

        return Repeater::make("answers.{$key}")
            ->label($this->label($data))
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Name')
                    ->maxLength(255)
                    ->required(),
                $relationship,
                TextInput::make('phone_number')
                    ->label('Phone Number')
                    ->phone()
                    ->required(),
                TextInput::make('email')->email()->required(),
                Radio::make('wants_text_updates')
                    ->label('Enroll this phone number in EAC Text Message Updates?')
                    ->helperText($textMessagePolicyReference === null
                        ? 'Select a published Text Message Updates Policy version before publishing this form.'
                        : app(EacFormContentProvider::class)->textMessageUpdatesHelp($textMessagePolicyReference))
                    ->boolean('Yes', 'No')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->minItems($minimum)
            ->defaultItems($defaultItems)
            ->afterStateHydrated(function (Repeater $component, Get $get, mixed $state) use ($context, $data, $defaultItems): void {
                if ($context->disabled($data, $get) || filled($state)) {
                    return;
                }

                $component->state(array_fill(0, $defaultItems, []));
            })
            ->reorderable(false)
            ->disabled($context->globallyDisabled)
            ->required();
    }

    public function comparisonProperties(array $data): array
    {
        return [
            'minimum contacts' => $data['min_items'] ?? 1,
            'default contacts' => $data['default_items'] ?? 2,
            'text message policy' => $data['text_message_policy_reference'] ?? null,
        ];
    }
}
