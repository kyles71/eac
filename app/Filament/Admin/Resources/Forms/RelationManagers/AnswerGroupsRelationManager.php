<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\RelationManagers;

use App\Enums\FormAnswerType;
use App\Enums\FormResponseStatus;
use App\Forms\FormDefinition;
use App\Models\Form;
use App\Models\FormAnswerGroup;
use App\Models\FormField;
use App\Models\FormVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class AnswerGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'answerGroups';

    protected static ?string $title = 'Repeatable Answers';

    public function table(Table $table): Table
    {
        $fields = $this->reportableFields();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereHas('response', fn (Builder $query): Builder => $query
                    ->where('status', FormResponseStatus::Submitted))
                ->with(['answers.field', 'response.assignment.subject', 'response.assignment.respondent']))
            ->columns([
                TextColumn::make('block_label')
                    ->label('Block')
                    ->state(fn (FormAnswerGroup $record): string => $this->blockLabels()[$record->block_key] ?? $record->block_key),
                TextColumn::make('subject_label')
                    ->label('Subject')
                    ->state(fn (FormAnswerGroup $record): string => $this->modelLabel($record->response->assignment->subject)),
                TextColumn::make('respondent_label')
                    ->label('Respondent')
                    ->state(fn (FormAnswerGroup $record): string => $this->modelLabel($record->response->assignment->respondent)),
                TextColumn::make('version.version')->label('Version')->sortable(),
                TextColumn::make('position')->sortable(),
                TextColumn::make('response.submitted_at')->label('Submitted At')->dateTime()->sortable(),
                ...$fields->map(fn (FormField $field): TextColumn => TextColumn::make("answer_{$field->id}")
                    ->label($this->fieldLabel($field))
                    ->state(fn (FormAnswerGroup $record): mixed => $record->answers
                        ->firstWhere('form_field_id', $field->id)
                        ?->value())
                    ->toggleable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('answers', fn (Builder $query): Builder => $query
                            ->where('form_field_id', $field->id)
                            ->where($this->valueColumn($field), 'like', "%{$search}%"))))
                    ->all(),
            ])
            ->filters([
                SelectFilter::make('block_key')
                    ->label('Block')
                    ->options($this->blockLabels()),
                SelectFilter::make('form_version_id')
                    ->label('Version')
                    ->options(fn (): array => $this->formRecord()
                        ->versions()
                        ->orderBy('version')
                        ->get()
                        ->mapWithKeys(fn (FormVersion $version): array => [$version->id => $version->versionLabel()])
                        ->all()),
            ])
            ->recordActions([]);
    }

    /**
     * @return Collection<int, FormField>
     */
    private function reportableFields(): Collection
    {
        $keys = $this->formRecord()
            ->versions()
            ->get()
            ->flatMap(fn (FormVersion $version): array => app(FormDefinition::class)->fields($version->schema))
            ->whereNotNull('block_key')
            ->pluck('key')
            ->unique();

        return $this->formRecord()->fields()->whereIn('key', $keys)->get();
    }

    /**
     * @return array<string, string>
     */
    private function blockLabels(): array
    {
        return $this->formRecord()
            ->versions()
            ->latest('version')
            ->get()
            ->flatMap(fn (FormVersion $version): array => $this->repeatableBlocks($version->schema))
            ->unique()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, string>
     */
    private function repeatableBlocks(array $blocks): array
    {
        $repeatable = [];

        foreach ($blocks as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (($block['type'] ?? null) === 'emergency_contacts') {
                $repeatable[(string) $data['key']] = (string) ($data['label'] ?? 'Emergency Contacts');
            }

            if (is_array($data['components'] ?? null)) {
                $repeatable = [...$repeatable, ...$this->repeatableBlocks($data['components'])];
            }
        }

        return $repeatable;
    }

    private function fieldLabel(FormField $field): string
    {
        return str($field->key)->after('.')->headline()->toString();
    }

    private function modelLabel(?\Illuminate\Database\Eloquent\Model $model): string
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

    private function valueColumn(FormField $field): string
    {
        return match ($field->answer_type) {
            FormAnswerType::String => 'value_string',
            FormAnswerType::Text => 'value_text',
            FormAnswerType::Integer => 'value_integer',
            FormAnswerType::Decimal => 'value_decimal',
            FormAnswerType::Boolean => 'value_boolean',
            FormAnswerType::Date => 'value_date',
            FormAnswerType::DateTime => 'value_datetime',
        };
    }

    private function formRecord(): Form
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof Form) {
            throw new LogicException('The repeatable answer relation manager requires a Form owner record.');
        }

        return $record;
    }
}
