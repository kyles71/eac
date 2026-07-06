<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\RelationManagers;

use App\Enums\FormAnswerType;
use App\Enums\FormResponseStatus;
use App\Forms\FormDefinition;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAssignment;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Responses';

    public function table(Table $table): Table
    {
        $fields = $this->reportableFields();

        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                FormAssignment::applyCompletedConstraint($query);

                return $query->with(['latestSubmittedResponse.answers.field', 'respondent', 'subject']);
            })
            ->columns([
                TextColumn::make('respondent_label')
                    ->label('Respondent')
                    ->state(fn (FormAssignment $record): string => $this->modelLabel($record->respondent)),
                TextColumn::make('subject_label')
                    ->label('Subject')
                    ->state(fn (FormAssignment $record): string => $record->subject === null ? '-' : $this->modelLabel($record->subject)),
                TextColumn::make('version.version')->label('Required Version')->sortable(),
                TextColumn::make('latestSubmittedResponse.signature')->label('Signer')->searchable(),
                TextColumn::make('latestSubmittedResponse.submitted_at')->label('Submitted At')->dateTime()->sortable(),
                ...$fields->map(fn (FormField $field): TextColumn => $this->answerColumn($field))->all(),
            ])
            ->filters([
                SelectFilter::make('form_version_id')
                    ->label('Version')
                    ->options(fn (): array => $this->formRecord()
                        ->versions()
                        ->orderBy('version')
                        ->get()
                        ->mapWithKeys(fn (FormVersion $version): array => [$version->id => $version->versionLabel()])
                        ->all()),
                SelectFilter::make('subject_id')
                    ->label('Student')
                    ->options(fn (): array => $this->formRecord()
                        ->assignments()
                        ->with('subject')
                        ->get()
                        ->mapWithKeys(function (FormAssignment $assignment): array {
                            $subject = $assignment->subject;

                            return $subject instanceof Student
                                ? [$subject->id => $subject->displayName()]
                                : [];
                        })
                        ->sort()
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query
                            ->where('subject_type', (new Student())->getMorphClass())
                            ->where('subject_id', $data['value'])
                        : $query)
                    ->searchable(),
                Filter::make('signer')
                    ->schema([TextInput::make('value')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas(
                            'latestSubmittedResponse',
                            fn (Builder $query): Builder => $query->where('signature', 'like', "%{$data['value']}%"),
                        )
                        : $query),
                Filter::make('submitted_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['from'] ?? null),
                            fn (Builder $query): Builder => $query->whereHas(
                                'latestSubmittedResponse',
                                fn (Builder $query): Builder => $query->whereDate('submitted_at', '>=', $data['from']),
                            ),
                        )
                        ->when(
                            filled($data['until'] ?? null),
                            fn (Builder $query): Builder => $query->whereHas(
                                'latestSubmittedResponse',
                                fn (Builder $query): Builder => $query->whereDate('submitted_at', '<=', $data['until']),
                            ),
                        )),
                ...$fields->map(fn (FormField $field) => $this->answerFilter($field))->all(),
            ])
            ->recordActions([]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, FormField>
     */
    private function reportableFields()
    {
        $form = $this->formRecord();
        $keys = $form->versions()
            ->get()
            ->flatMap(fn (FormVersion $version): array => app(FormDefinition::class)->fields($version->schema))
            ->whereNull('block_key')
            ->pluck('key')
            ->unique();

        return $form->fields()->whereIn('key', $keys)->get();
    }

    private function answerColumn(FormField $field): TextColumn
    {
        return TextColumn::make("answer_{$field->id}")
            ->label($this->fieldLabel($field))
            ->state(fn (FormAssignment $record): mixed => $record->latestSubmittedResponse
                ?->answers
                ->firstWhere('form_field_id', $field->id)
                ?->value())
            ->toggleable()
            ->searchable(
                query: fn (Builder $query, string $search): Builder => $query->whereHas(
                    'latestSubmittedResponse.answers',
                    fn (Builder $query): Builder => $query
                        ->where('form_field_id', $field->id)
                        ->where($this->valueColumn($field), 'like', "%{$search}%"),
                ),
            )
            ->sortable(
                query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    FormAnswer::query()
                        ->select($this->valueColumn($field))
                        ->join('form_responses', 'form_responses.id', '=', 'form_answers.form_response_id')
                        ->whereColumn('form_responses.form_assignment_id', 'form_assignments.id')
                        ->where('form_answers.form_field_id', $field->id)
                        ->where('form_responses.status', FormResponseStatus::Submitted)
                        ->latest('form_responses.submitted_at')
                        ->limit(1),
                    $direction,
                ),
            );
    }

    private function answerFilter(FormField $field): Filter|TernaryFilter
    {
        if ($field->answer_type === FormAnswerType::Boolean) {
            return TernaryFilter::make("answer_{$field->id}")
                ->label($this->fieldLabel($field))
                ->queries(
                    true: fn (Builder $query): Builder => $this->whereAnswer($query, $field, true),
                    false: fn (Builder $query): Builder => $this->whereAnswer($query, $field, false),
                );
        }

        $input = match ($field->answer_type) {
            FormAnswerType::Date => DatePicker::make('value'),
            FormAnswerType::DateTime => DateTimePicker::make('value'),
            FormAnswerType::Integer, FormAnswerType::Decimal => TextInput::make('value')->numeric(),
            default => TextInput::make('value'),
        };

        return Filter::make("answer_{$field->id}")
            ->label($this->fieldLabel($field))
            ->schema([$input])
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                ? $this->whereAnswer($query, $field, $data['value'])
                : $query);
    }

    private function whereAnswer(Builder $query, FormField $field, mixed $value): Builder
    {
        return $query->whereHas(
            'latestSubmittedResponse.answers',
            fn (Builder $query): Builder => $query
                ->where('form_field_id', $field->id)
                ->where($this->valueColumn($field), $value),
        );
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

    private function fieldLabel(FormField $field): string
    {
        $block = $this->formRecord()
            ->versions()
            ->latest('version')
            ->get()
            ->map(fn (FormVersion $version): ?array => app(FormDefinition::class)->blockForField($version->schema, $field->key))
            ->first(fn (?array $block): bool => $block !== null);

        return (string) ($block['data']['label'] ?? $field->key);
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

    private function formRecord(): Form
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof Form) {
            throw new LogicException('The response relation manager requires a Form owner record.');
        }

        return $record;
    }
}
