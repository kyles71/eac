<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Models\FormAssignment;
use App\Models\FormResponse;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class FormAssignmentInfolist
{
    public static function configure(Schema $schema, FormAssignment $assignment): Schema
    {
        $assignment->loadMissing(['latestSubmittedResponse.version', 'version', 'subject']);
        $response = $assignment->latestSubmittedResponse;
        $responseVersion = $response instanceof FormResponse
            ? $response->version
            : $assignment->version;
        $responsePath = 'latestSubmittedResponse.response_state.answers';

        return $schema->components([
            Section::make('Submission')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('form.name')->label('Form'),
                    TextEntry::make('submitted_version')
                        ->label('Version')
                        ->state($responseVersion->version),
                    TextEntry::make('subject_label')
                        ->label('Subject')
                        ->state(fn (FormAssignment $record): string => self::modelLabel($record->subject)),
                    TextEntry::make('latestSubmittedResponse.submitted_at')->label('Submitted At')->dateTime()->placeholder('-'),
                    TextEntry::make('latestSubmittedResponse.signature')->label('Signature')->placeholder('-'),
                    TextEntry::make('latestSubmittedResponse.date_signed')->label('Date Signed')->date()->placeholder('-'),
                ]),
            ...self::blocks($responseVersion->schema, $responsePath),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, Component>
     */
    private static function blocks(array $blocks, string $responsePath): array
    {
        $components = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $key = (string) ($data['key'] ?? '');
            $component = match ($type) {
                'section' => Section::make((string) ($data['heading'] ?? 'Section'))
                    ->description(filled($data['description'] ?? null) ? (string) $data['description'] : null)
                    ->columns((int) ($data['columns'] ?? 1))
                    ->columnSpanFull()
                    ->schema(self::blocks($data['components'] ?? [], $responsePath)),
                'text' => Text::make(($data['is_html'] ?? false)
                    ? new HtmlString((string) ($data['content'] ?? ''))
                    : (string) ($data['content'] ?? ''))->columnSpanFull(),
                'subject', 'student' => TextEntry::make('subject_label')
                    ->label((string) ($data['label'] ?? 'Subject'))
                    ->state(fn (FormAssignment $record): string => self::modelLabel($record->subject))
                    ->columnSpanFull(),
                'emergency_contacts' => RepeatableEntry::make("{$responsePath}.{$key}")
                    ->label((string) ($data['label'] ?? 'Emergency Contacts'))
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('relationship'),
                        TextEntry::make('phone_number')->label('Phone Number'),
                        TextEntry::make('email')->placeholder('-'),
                        IconEntry::make('wants_text_updates')->label('Text Updates')->boolean(),
                    ]),
                'checkbox', 'toggle' => IconEntry::make("{$responsePath}.{$key}")
                    ->label((string) ($data['label'] ?? 'Answer'))
                    ->boolean(),
                'date' => TextEntry::make("{$responsePath}.{$key}")
                    ->label((string) ($data['label'] ?? 'Answer'))
                    ->date()
                    ->placeholder('-'),
                'short_text', 'email', 'phone', 'long_text', 'number', 'select', 'radio' => TextEntry::make("{$responsePath}.{$key}")
                    ->label((string) ($data['label'] ?? 'Answer'))
                    ->placeholder('-'),
                default => null,
            };

            if ($component instanceof Component) {
                $components[] = $component;
            }
        }

        return $components;
    }

    private static function modelLabel(?\Illuminate\Database\Eloquent\Model $model): string
    {
        if ($model === null) {
            return '-';
        }

        if (method_exists($model, 'displayName')) {
            return (string) $model->displayName();
        }

        if (filled($model->getAttribute('fullName'))) {
            return (string) $model->getAttribute('fullName');
        }

        if (filled($model->getAttribute('name'))) {
            return (string) $model->getAttribute('name');
        }

        return class_basename($model).' #'.$model->getKey();
    }
}
