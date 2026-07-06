<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Schemas;

use App\Enums\FormAnswerType;
use App\Enums\FormPurpose;
use App\Forms\FormDefinition;
use App\Forms\FormMappingRegistry;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use LogicException;

final class FormVersionForm
{
    public static function configure(Schema $schema, FormPurpose $purpose): Schema
    {
        return $schema
            ->components([
                Section::make('Version Settings')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('requires_signature')
                            ->label('Require signature and signed date')
                            ->default(false)
                            ->required(),
                        DateTimePicker::make('valid_until')
                            ->label('Valid Until')
                            ->helperText('Existing submissions remain tied to this version and expiration date.'),
                    ]),
                Section::make('Form Builder')
                    ->columnSpanFull()
                    ->schema([
                        Builder::make('schema')
                            ->label('')
                            ->blocks(self::blocks($purpose))
                            ->collapsible()
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, Block>
     */
    private static function blocks(FormPurpose $purpose): array
    {
        return [
            Block::make('section')
                ->label(fn (?array $state): string => $state['heading'] ?? 'Section')
                ->schema([
                    ...self::identityFields('Section'),
                    TextInput::make('heading')->required()->live(onBlur: true),
                    Textarea::make('description')->rows(2),
                    Select::make('columns')
                        ->options([1 => 'One column', 2 => 'Two columns'])
                        ->default(1)
                        ->required(),
                    Builder::make('components')
                        ->label('Section Fields')
                        ->blocks(self::contentBlocks($purpose))
                        ->collapsible()
                        ->required()
                        ->columnSpanFull(),
                ]),
            ...self::contentBlocks($purpose),
        ];
    }

    /**
     * @return array<int, Block>
     */
    private static function contentBlocks(FormPurpose $purpose): array
    {
        return [
            Block::make('text')
                ->label('Instructions')
                ->schema([
                    ...self::identityFields('Instructions'),
                    Textarea::make('content')->required()->rows(4),
                ]),
            Block::make('subject')
                ->label('Assigned Subject')
                ->schema([
                    ...self::identityFields('Assigned Subject'),
                    TextInput::make('label')->default('Subject')->required(),
                ]),
            self::questionBlock('short_text', 'Short Text', $purpose),
            self::questionBlock('email', 'Email', $purpose),
            self::questionBlock('phone', 'Phone Number', $purpose),
            self::questionBlock('long_text', 'Long Text', $purpose, [
                TextInput::make('rows')->numeric()->default(3)->minValue(2)->maxValue(12),
            ]),
            self::questionBlock('number', 'Number', $purpose),
            self::questionBlock('date', 'Date', $purpose, [
                Toggle::make('default_today')
                    ->label('Default to today')
                    ->default(false),
            ]),
            self::questionBlock('checkbox', 'Checkbox Consent', $purpose, [
                Toggle::make('accepted')
                    ->label('Must be checked')
                    ->default(true),
            ]),
            self::questionBlock('toggle', 'Toggle', $purpose),
            self::questionBlock('select', 'Select', $purpose, [
                KeyValue::make('options')
                    ->keyLabel('Stored Value')
                    ->valueLabel('Label')
                    ->required()
                    ->columnSpanFull(),
            ]),
            self::questionBlock('radio', 'Radio', $purpose, [
                KeyValue::make('options')
                    ->keyLabel('Stored Value')
                    ->valueLabel('Label')
                    ->required()
                    ->columnSpanFull(),
            ]),
            ...($purpose === FormPurpose::MedicalWaiver ? [
                Block::make('emergency_contacts')
                    ->label('Emergency Contacts')
                    ->schema([
                        ...self::identityFields('Emergency Contacts'),
                        TextInput::make('label')->default('Emergency Contacts')->required(),
                        TextInput::make('min_items')->numeric()->default(1)->minValue(1)->required(),
                    ]),
            ] : []),
        ];
    }

    /**
     * @param  array<int, \Filament\Schemas\Components\Component>  $extra
     */
    private static function questionBlock(string $name, string $label, FormPurpose $purpose, array $extra = []): Block
    {
        return Block::make($name)
            ->label(fn (?array $state): string => $state['label'] ?? $label)
            ->schema([
                ...self::identityFields($label),
                TextInput::make('label')->required()->live(onBlur: true),
                Textarea::make('help')->rows(2),
                Toggle::make('required')->default(false),
                Select::make('mapping')
                    ->label('Application Mapping')
                    ->helperText('Optional. Mappings are validated when this version is published.')
                    ->options(app(FormMappingRegistry::class)->options($purpose, self::answerType($name)))
                    ->searchable(false),
                TextInput::make('visible_when_key')
                    ->label('Show When Field Key')
                    ->helperText('Optional stable key of an earlier field.'),
                TextInput::make('visible_when_value')
                    ->label('Show When Value')
                    ->helperText('For toggles and checkboxes, use true or false.'),
                Select::make('column_span')
                    ->options([1 => 'One column', 2 => 'Full width'])
                    ->default(1)
                    ->required(),
                ...$extra,
            ]);
    }

    /**
     * @return array<int, TextInput>
     */
    private static function identityFields(string $label): array
    {
        return [
            TextInput::make('key')
                ->label("{$label} Key")
                ->helperText('Stable identity used for version history and visibility rules.')
                ->default(fn (): string => app(FormDefinition::class)->newKey())
                ->readOnly()
                ->required()
                ->columnSpanFull(),
        ];
    }

    private static function answerType(string $blockType): FormAnswerType
    {
        return match ($blockType) {
            'short_text', 'email', 'phone', 'select', 'radio' => FormAnswerType::String,
            'long_text' => FormAnswerType::Text,
            'number' => FormAnswerType::Decimal,
            'checkbox', 'toggle' => FormAnswerType::Boolean,
            'date' => FormAnswerType::Date,
            default => throw new LogicException("Unsupported form builder block type [{$blockType}]."),
        };
    }
}
