<?php

declare(strict_types=1);

namespace App\Forms;

use App\Models\FormAssignment;
use App\Models\FormVersion;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

final readonly class FormSchemaCompiler
{
    /**
     * @return array<int, Component>
     */
    public function components(FormVersion $version, ?FormAssignment $assignment = null, bool $disabled = false): array
    {
        return $this->compileBlocks(
            $version->schema,
            $assignment,
            $disabled,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, Component>
     */
    private function compileBlocks(
        array $blocks,
        ?FormAssignment $assignment,
        bool $disabled,
    ): array {
        $components = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $component = match ($type) {
                'section' => Section::make((string) ($data['heading'] ?? 'Section'))
                    ->description(filled($data['description'] ?? null) ? (string) $data['description'] : null)
                    ->columnSpanFull()
                    ->columns((int) ($data['columns'] ?? 1))
                    ->schema($this->compileBlocks($data['components'] ?? [], $assignment, $disabled)),
                'text' => Text::make(($data['is_html'] ?? false)
                    ? new HtmlString((string) ($data['content'] ?? ''))
                    : (string) ($data['content'] ?? ''))->columnSpanFull(),
                'subject', 'student' => Placeholder::make('assigned_subject')
                    ->label((string) ($data['label'] ?? 'Subject'))
                    ->content($this->assignedSubjectName($assignment))
                    ->columnSpanFull(),
                'emergency_contacts' => $this->emergencyContacts($data, $disabled),
                default => $this->question($type, $data, $disabled),
            };

            if ($component instanceof Component) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function question(string $type, array $data, bool $disabled): ?Field
    {
        $key = (string) ($data['key'] ?? '');
        $name = "answers.{$key}";
        $field = match ($type) {
            'short_text' => TextInput::make($name),
            'email' => TextInput::make($name)->email(),
            'phone' => TextInput::make($name)->tel(),
            'long_text' => Textarea::make($name)->rows((int) ($data['rows'] ?? 3)),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)
                ->default(($data['default_today'] ?? false)
                    ? now((string) config('app.display_timezone', config('app.timezone')))->toDateString()
                    : null),
            'checkbox' => Checkbox::make($name)
                ->accepted((bool) ($data['accepted'] ?? false)),
            'toggle' => Toggle::make($name),
            'select' => Select::make($name)->options($this->options($data))->searchable(false),
            'radio' => Radio::make($name)->options($this->options($data)),
            default => null,
        };

        if (! $field instanceof Field) {
            return null;
        }

        $field
            ->label((string) ($data['label'] ?? 'Question'))
            ->helperText(filled($data['help'] ?? null)
                ? (($data['help_is_html'] ?? false) ? new HtmlString((string) $data['help']) : (string) $data['help'])
                : null)
            ->required((bool) ($data['required'] ?? false))
            ->columnSpan((int) ($data['column_span'] ?? 1))
            ->disabled($disabled);

        if (filled($data['visible_when_key'] ?? null)) {
            $dependentKey = (string) $data['visible_when_key'];
            $expectedValue = json_encode((string) ($data['visible_when_value'] ?? ''), JSON_THROW_ON_ERROR);

            $field->visibleJs("String(\$get('answers.{$dependentKey}')) === {$expectedValue}");
        }

        return $field;
    }

    private function assignedSubjectName(?FormAssignment $assignment): string
    {
        if ($assignment === null || $assignment->subject === null) {
            return '-';
        }

        $subject = $assignment->subject;

        if (method_exists($subject, 'displayName')) {
            return (string) $subject->displayName();
        }

        if (filled($subject->getAttribute('name'))) {
            return (string) $subject->getAttribute('name');
        }

        return class_basename($subject).' #'.$subject->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emergencyContacts(array $data, bool $disabled): Repeater
    {
        $key = (string) ($data['key'] ?? '');

        return Repeater::make("answers.{$key}")
            ->label((string) ($data['label'] ?? 'Emergency Contacts'))
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('relationship')->required(),
                TextInput::make('phone_number')->tel()->required(),
                TextInput::make('email')->email()->required(),
                Radio::make('wants_text_updates')
                    ->label('Enroll this phone number in EAC Text Message Updates?')
                    ->helperText(filled($data['text_updates_help'] ?? null) ? new HtmlString((string) $data['text_updates_help']) : null)
                    ->boolean('Yes', 'No')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->minItems(max(1, (int) ($data['min_items'] ?? 1)))
            ->defaultItems(max(1, (int) ($data['min_items'] ?? 1)))
            ->reorderable(false)
            ->disabled($disabled)
            ->required();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function options(array $data): array
    {
        return collect($data['options'] ?? [])
            ->filter(fn (mixed $label, mixed $value): bool => filled($value) && filled($label))
            ->mapWithKeys(fn (mixed $label, mixed $value): array => [(string) $value => (string) $label])
            ->all();
    }
}
